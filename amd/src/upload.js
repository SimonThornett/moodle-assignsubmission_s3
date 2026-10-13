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

/**
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 */
// <reference types="aws-sdk" />

define(['core/notification', 'core/ajax', 'core/str'], function(notification, ajax, str) {

    let module = {};

    const concurrency = 6;

    let percentComplete = 0;
    /**
     * Init function to send the file to a pre-signed URL to S3.
     *
     * @param {number} cmid
     * @param {number} chunkSize
     * @param {number} maxfiles
     */
    module.init = function(cmid, chunkSize, maxfiles) {
        // Get the various elements and containers.
        const form = document.querySelector('.editsubmissionform form');
        const statement = document.getElementById('id_submissionstatement');
        const buttons = document.getElementById('fgroup_id_buttonar');
        const file = document.querySelector('input[name="s3_file"]');
        const fileContainer = document.getElementById('s3-file-container');
        const progressContainer = document.getElementById('progress-bar-container');

        form.addEventListener('submit', function(e) {
            // Cancelled, so do nothing.
            if (e.submitter.name === 'cancel') {
                return;
            }
            // No file submitted, so do nothing.
            if (file.files.length <= 0) {
                return;
            }
            if (file.files.length > maxfiles) {
                Promise.all([
                    str.get_string('error', 'core'),
                    str.get_string('error:maxfiles', 'assignsubmission_s3', maxfiles)
                ]).then(([title, message]) => notification.alert(title, message))
                .catch(notification.exception);
                e.preventDefault();
                return;
            }
            if (!statement || (statement && statement.checked)) {
                e.preventDefault();
                buttons.classList.add("hidden");
                uploadFiles(Array.from(file.files), cmid, chunkSize, form, file, fileContainer, progressContainer)
                    .catch(error => {
                        resetUploadUi(fileContainer, progressContainer);
                        notification.exception(error);
                    });
            }
        });
    };

    /**
     * Restore the submission form UI when an error occurs.
     *
     * @param {HTMLElement} fileContainer
     * @param {HTMLElement} progressContainer
     */
    function resetUploadUi(fileContainer, progressContainer) {
        fileContainer.classList.remove("hidden");
        progressContainer.classList.add("hidden");
        const buttons = document.getElementById('fgroup_id_buttonar');
        if (buttons) {
            buttons.classList.remove("hidden");
        }
    }

    /**
     * Upload each file sequentially so the server can reserve and enforce file-count slots.
     *
     * @param {File[]} files
     * @param {number} cmid
     * @param {number} chunkSize
     * @param {HTMLFormElement} form
     * @param {HTMLInputElement} fileinput
     * @param {HTMLElement} fileContainer
     * @param {HTMLElement} progressContainer
     * @returns {Promise<void>}
     */
    async function uploadFiles(files, cmid, chunkSize, form, fileinput, fileContainer, progressContainer) {
        const uploadPlans = [];

        fileContainer.classList.add("hidden");
        progressContainer.classList.remove("hidden");
        for (const [index, upload] of files.entries()) {
            const result = await callAjax('assignsubmission_s3_get_multipart_upload', {
                cmid: cmid,
                filename: upload.name,
                mimetype: upload.type,
                filesize: upload.size,
                replaceexisting: index === 0
            });
            if (result.error) {
                resetUploadUi(fileContainer, progressContainer);
                notification.alert(result.error_title, result.error_msg);
                return;
            }
            uploadPlans.push({upload: upload, result: result});
        }

        percentComplete = 0;
        const progress = document.getElementById('progress-bar');
        const progresspercent = document.getElementById('progress-bar-percent');
        progress.setAttribute('aria-valuenow', percentComplete);
        progress.style.width = percentComplete + "%";
        progresspercent.innerText = Math.round(percentComplete) + "%";
        const completions = [];

        for (const plan of uploadPlans) {
            const upload = plan.upload;
            const result = plan.result;
            const parts = [];
            const count = result.s3urls.length;
            let nextpart = 0;
            const workers = Array.from({length: Math.min(concurrency, count)}, async() => {
                while (nextpart < count) {
                    const index = nextpart++;
                    await uploadPart(upload, index + 1, result.s3urls[index], count, files.length, chunkSize, parts);
                }
            });
            await Promise.all(workers);

            completions.push({
                fileid: result.fileid,
                cmid: cmid,
                parts: JSON.stringify(parts),
                uploadid: result.uploadid,
            });
        }

        for (const args of completions) {
            const completion = await callAjax('assignsubmission_s3_send_multipart_complete', args);
            if (completion.error) {
                resetUploadUi(fileContainer, progressContainer);
                notification.alert(completion.error_title, completion.error_msg);
                return;
            }
        }

        fileinput.value = '';
        form.submit();
    }

    /**
     * Run one Moodle AJAX request.
     *
     * @param {string} methodname
     * @param {Object} args
     * @returns {Promise<Object>}
     */
    function callAjax(methodname, args) {
        return new Promise((resolve, reject) => {
            const request = ajax.call([{methodname: methodname, args: args}])[0];
            request.done(resolve).fail(reject);
        });
    }

    /**
     *
     * @param {File} file
     * @param {Number} partNumber
     * @param {String} url
     * @param {Number} count
     * @param {Number} fileCount
     * @param {Number} chunkSize
     * @param {Array} parts
     * @returns {Promise<void>}
     */
    async function uploadPart(file, partNumber, url, count, fileCount, chunkSize, parts) {
        const start = (partNumber - 1) * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const blob = file.slice(start, end);
        const progress = document.getElementById('progress-bar');
        const progresspercent = document.getElementById('progress-bar-percent');

        const xhr = new XMLHttpRequest();
        await new Promise((resolve, reject) => {
            let previousPercent = 0;
            xhr.upload.addEventListener("progress", (event) => {
                if (event.lengthComputable) {
                    let difference = ((event.loaded / event.total) * 100) - previousPercent;
                    percentComplete = Math.min(100, percentComplete + (difference / count / fileCount));
                    progress.setAttribute('aria-valuenow', percentComplete);
                    progress.style.width = percentComplete + "%";
                    progresspercent.innerText = Math.round(percentComplete) + "%";
                    previousPercent = (event.loaded / event.total) * 100;
                }
            });
            xhr.addEventListener("loadend", () => {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    resolve();
                } else {
                    reject(new Error('Unable to upload file part to S3.'));
                }
            });

            xhr.open("PUT", url, true);
            xhr.setRequestHeader("Content-Type", "application/octet-stream");
            xhr.send(blob);
        });
        parts[partNumber - 1] = {ETag: xhr.getResponseHeader("ETag"), PartNumber: partNumber};
    }

    return module;
});
