<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace assignsubmission_s3;

use assign;
use assignsubmission_s3\event\connection_issue;
use assignsubmission_s3\submission_settings;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\MockHandler;
use context_module;
use Psr\Http\Message\RequestInterface;
use stdClass;

/**
 * S3 class to handle object processing and tagging.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class s3 {
    /** @var S3Client|null The S3 Client we use to interact with AWS via the API. */
    public ?S3Client $client = null;

    /** @var string|mixed The endpoint setting. */
    private string $endpoint;

    /** @var string|mixed The region setting. */
    private string $region;

    /** @var string|mixed The bucket setting. */
    private string $bucket;

    /** @var string|mixed The keyid setting. */
    private string $keyid;

    /** @var string|mixed The secret setting. */
    private string $secret;

    /** @var bool Flag to identify if an error has been thrown. */
    private bool $haserror = false;

    /** @var string The error message. */
    private string $error = '';

    /** @var MockHandler|null Optional handler that is used for PHPUnit testing. */
    private ?MockHandler $handler;

    /** @var assign The assignment we're processing the upload for. */
    private assign $assignment;

    /** @var string The upload ID for the multipart upload. */
    private string $uploadid;

    /**
     * Constructor class.
     *
     * @param assign $assignment The assignment we'll be processing.
     * @param ?MockHandler $handler Allows us to pass a mock handler for Unit testing.
     */
    public function __construct(assign $assignment, ?MockHandler $handler = null) {
        $this->handler = $handler;
        $this->assignment = $assignment;
        $credentials = submission_settings::get_credentials($assignment);
        $this->endpoint = $credentials['baseurl'];
        $this->region = $credentials['region'];
        $this->bucket = $credentials['bucket'];
        $this->keyid = $credentials['key'];
        $this->secret = $credentials['secret'];

        if ($this->is_configured()) {
            // Site been configured, so attempt connection.
            $this->client = new S3Client([
                'endpoint' => $this->endpoint,
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => [
                    'key' => $this->keyid,
                    'secret' => $this->secret,
                ],
                'handler' => $this->handler,
            ]);
            // If we fail the permission check flag an error.
            if (!$this->has_permissions()) {
                $this->haserror = true;
            }
        }
    }

    /**
     * Check if the plugin has been configured.
     *
     * @return bool
     */
    private function is_configured(): bool {
        $configured = !empty($this->region) && !empty($this->bucket) && !empty($this->keyid) && !empty($this->secret);
        if (!$configured) {
            $this->trigger_error_event("Permission check failed: Region/Bucket/KeyID/Secret not defined in config.");
        }
        return $configured;
    }

    /**
     * Check if the defined settings allow for the required actions.
     *
     * @return bool
     */
    public function has_permissions(): bool {
        try {
            $result = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => 'permissions_check_file',
                'Body' => 'test content',
            ]);
        } catch (S3Exception $e) {
            $this->trigger_error_event("Permission check failed: {$e->getMessage()}");
            return false;
        }
        $statuscode = $result['@metadata']['statusCode'];
        if ($statuscode === 200) {
            return true;
        } else {
            $this->trigger_error_event("Permission check failed: Status code $statuscode");
            return false;
        }
    }

    /**
     * Add the defined tag to the object.
     *
     * @param string $tagname
     * @param string $key
     * @return void
     */
    public function add_tag(string $tagname, string $key): void {
        try {
            $this->client->putObjectTagging([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Tagging' => [
                    'TagSet' => [
                        [
                            'Key' => 'glaciertag',
                            'Value' => $tagname,
                        ],
                    ],
                ],
            ]);
        } catch (S3Exception $e) {
            $this->trigger_error_event("Add tag failed: {$e->getMessage()}");
        }
    }

    /**
     * Return presigned URL for the GetObject request.
     *
     * @param stdClass $object
     * @return RequestInterface|null
     */
    public function retrieve_object(stdClass $object): ?RequestInterface {
        if ($this->is_available($object->uuid)) {
            return $this->client->createPresignedRequest(
                $this->client->getCommand('GetObject', [
                    'Bucket' => $this->bucket,
                    'Key' => $object->uuid,
                    'ResponseContentDisposition' => 'attachment; filename="' . $object->name . '"',
                    'ResponseContentType' => $object->mimetype,
                ]),
                '+3 hour'
            );
        }
        return null;
    }

    /**
     * Restore the object from the glacier archive if restore not already in progress.
     *
     * @param string $key S3 object key to restore
     * @return array Status and expiry date (if applicable).
     */
    public function restore_object($key): array {
        $return = [
            'status' => false,
            'expiry-date' => null,
        ];

        // Get the objects metadata.
        $head = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        // If we have a restore in progress or requested it will come back in the head.
        if (isset($head['Restore'])) {
            // We already have a restore in progress.
            if ($head['Restore'] === 'ongoing-request="true"') {
                $return['status'] = true;
                return $return;
            }
            // The restore was completed and expiration date is in the future, so return the expiration date.
            if (str_contains($head['Restore'], 'ongoing-request="false"') && strtotime($head['Expires']) >= time()) {
                $return['status'] = true;
                $return['expiry-date'] = strtotime($head['Expires']);
                return $return;
            }
        }

        // Otherwise we either don't have an existing restore, or the previous one has expired, so make a new request.
        $duration = get_config('assignsubmission_s3', 'glacierrestoreduration') ?? 7 * DAYSECS;
        try {
            $result = $this->client->restoreObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'RestoreRequest' => [
                    'Days' => ceil($duration / DAYSECS),
                ],
            ]);
        } catch (S3Exception $e) {
            $this->trigger_error_event("Restore object failed: {$e->getMessage()}");
        }
        $statuscode = $result['@metadata']['statusCode'];
        // Action is either already processed or accepted, either way, valid.
        if ($statuscode === 200 || $statuscode === 202) {
            $return['status'] = true;
        }
        return $return;
    }

    /**
     * Create a copy of an existing object.
     * This is mainly used when backing up and restoring an assignment to the same site.
     *
     * @param string $existingkey S3 object key to copy
     * @param string $newkey S3 object key for the copy
     * @return void
     */
    public function copy_object($existingkey, $newkey): void {
        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'CopySource' => "$this->bucket/$existingkey",
                'Key' => $newkey,
            ]);
        } catch (S3Exception $e) {
            $this->trigger_error_event("Copy object failed: {$e->getMessage()}");
        }
    }

    /**
     * Delete the object in the bucket.
     *
     * @param string $key
     * @return bool
     */
    public function delete_object(string $key): bool {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        return !$this->is_available($key);
    }

    /**
     * Check if the object is available/exists.
     *
     * @param string $key
     * @return bool
     */
    private function is_available(string $key): bool {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    /**
     * Return presigned URL for the PutObject request.
     *
     * @param string $key
     * @return RequestInterface|null
     */
    public function create_presigned_request(string $key): ?RequestInterface {
        return $this->client->createPresignedRequest(
            $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]),
            '+3 hour'
        );
    }

    /**
     * Return presigned URL for the UploadPart request.
     *
     * @param string $key
     * @param int $part
     * @return RequestInterface|null
     */
    public function create_presigned_part_upload_requests(string $key, int $part): ?RequestInterface {
        if (!isset($this->uploadid)) {
            $this->create_mulitpart_upload_id($key);
        }
        return $this->client->createPresignedRequest(
            $this->client->getCommand('UploadPart', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $this->get_mulitpart_upload_id($key),
                'PartNumber' => $part,
            ]),
            '+3 hour'
        );
    }

    /**
     * Return presigned URL for the CompleteMultipartUpload request.
     *
     * @param string $uploadid
     * @param string $key
     * @param array $parts
     * @return void
     */
    public function send_presigned_part_upload_complete_request(string $uploadid, string $key, array $parts): void {
        $this->client->CompleteMultipartUpload([
            'Bucket'   => $this->bucket,
            'Key'      => $key,
            'UploadId' => $uploadid,
            'MultipartUpload' => [
                'Parts' => $parts,
            ],
        ]);
    }

    /**
     * Get the upload id to use in the multi part upload request.
     *
     * @param string $key
     * @return string
     */
    public function get_mulitpart_upload_id(string $key): string {
        if (!isset($this->uploadid)) {
            $this->create_mulitpart_upload_id($key);
        }
        return $this->uploadid;
    }

    /**
     * Create the upload id to use in the multi part upload request.
     *
     * @param string $key
     * @return void
     */
    private function create_mulitpart_upload_id(string $key): void {
        $create = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        $this->uploadid = $create['UploadId'];
    }

    /**
     * Has an error been generated?
     *
     * @return bool
     */
    public function has_error(): bool {
        return $this->haserror;
    }

    /**
     * Return the error generated.
     *
     * @return string
     */
    public function get_error(): string {
        return $this->error;
    }

    /**
     * Trigger the error event and set the error properties.
     *
     * @param string $error
     * @return void
     */
    private function trigger_error_event(string $error): void {
        global $USER;

        $this->haserror = true;
        $this->error = $error;
        $params = [
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'other' => [
                'error' => $this->get_error(),
            ],
            'relateduserid' => $USER->id,
        ];
        $event = connection_issue::create($params);
        $event->trigger();
    }
}
