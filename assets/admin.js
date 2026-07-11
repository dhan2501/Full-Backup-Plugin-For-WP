(function ($) {
	'use strict';

	let selectedPosts = {};

	function showNotice(message, isError) {
		const $notice = $('#wpcb-notice');
		$notice
			.removeClass('error')
			.toggleClass('error', !!isError)
			.html(message)
			.show();

		$('html, body').animate({ scrollTop: 0 }, 200);
	}

	function setButtonLoading($btn, loading) {
		if (loading) {
			$btn.data('original-text', $btn.text());
			$btn.prop('disabled', true).append('<span class="wpcb-spinner"></span>');
		} else {
			$btn.prop('disabled', false).text($btn.data('original-text') || $btn.text());
		}
	}

	function runBackupAction($btn, action) {
		setButtonLoading($btn, true);

		$.post(wpcbData.ajaxUrl, {
			action: action,
			nonce: wpcbData.nonce
		}).done(function (response) {
			setButtonLoading($btn, false);

			if (response.success) {
				const data = response.data;
				showNotice(
					data.message + ' <a href="' + data.url + '" download><strong>Download (' + formatBytes(data.size) + ')</strong></a>',
					false
				);
				addBackupRow(data);
			} else {
				showNotice((response.data && response.data.message) || 'Something went wrong.', true);
			}
		}).fail(function () {
			setButtonLoading($btn, false);
			showNotice('Request failed. Please try again.', true);
		});
	}

	function formatBytes(bytes) {
		if (!bytes) return '0 B';
		const units = ['B', 'KB', 'MB', 'GB'];
		let i = 0;
		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}
		return bytes.toFixed(1) + ' ' + units[i];
	}

	function addBackupRow(data) {
		const $list = $('#wpcb-backups-list');

		if ($list.find('table').length === 0) {
			$list.html(
				'<table class="wp-list-table widefat fixed striped">' +
				'<thead><tr><th>File</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>' +
				'<tbody></tbody></table>'
			);
		}

		const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
		const row = '<tr data-filename="' + data.file + '">' +
			'<td>' + data.file + '</td>' +
			'<td>' + formatBytes(data.size) + '</td>' +
			'<td>' + now + ' UTC</td>' +
			'<td>' +
			'<a href="' + data.url + '" class="button button-small" download>Download</a> ' +
			'<button class="button button-small wpcb-delete-backup" data-filename="' + data.file + '">Delete</button>' +
			'</td></tr>';

		$list.find('tbody').prepend(row);
	}

	function searchPosts() {
		const search = $('#wpcb-post-search').val();
		const postType = $('#wpcb-post-type-filter').val();
		const $results = $('#wpcb-post-results');

		$results.html('<p class="wpcb-hint">Searching…</p>');

		$.post(wpcbData.ajaxUrl, {
			action: 'wpcb_search_posts',
			nonce: wpcbData.nonce,
			search: search,
			post_type: postType
		}).done(function (response) {
			if (!response.success || !response.data.posts.length) {
				$results.html('<p class="wpcb-hint">No results found.</p>');
				return;
			}

			let html = '';
			response.data.posts.forEach(function (p) {
				const checked = selectedPosts[p.id] ? 'checked' : '';
				html += '<div class="wpcb-post-row">' +
					'<input type="checkbox" class="wpcb-post-checkbox" data-id="' + p.id + '" data-title="' + escapeHtml(p.title) + '" ' + checked + '>' +
					'<span class="wpcb-post-title">' + escapeHtml(p.title) + '</span>' +
					'<span class="wpcb-post-meta">' + p.type + ' · ' + p.status + ' · ' + p.date + '</span>' +
					'</div>';
			});

			$results.html(html);
		}).fail(function () {
			$results.html('<p class="wpcb-hint">Search failed. Try again.</p>');
		});
	}

	function escapeHtml(str) {
		return $('<div>').text(str).html();
	}

	function updateSelectedCount() {
		const count = Object.keys(selectedPosts).length;
		$('#wpcb-selected-count').text(count);
		$('#wpcb-export-selected').prop('disabled', count === 0);
	}

	$(document).on('click', '.wpcb-btn', function () {
		const $btn = $(this);
		const action = $btn.data('action');
		runBackupAction($btn, action);
	});

	$(document).on('click', '#wpcb-search-btn', function (e) {
		e.preventDefault();
		searchPosts();
	});

	$(document).on('keypress', '#wpcb-post-search', function (e) {
		if (e.which === 13) {
			e.preventDefault();
			searchPosts();
		}
	});

	$(document).on('change', '.wpcb-post-checkbox', function () {
		const id = $(this).data('id');
		if ($(this).is(':checked')) {
			selectedPosts[id] = $(this).data('title');
		} else {
			delete selectedPosts[id];
		}
		updateSelectedCount();
	});

	$(document).on('click', '#wpcb-export-selected', function () {
		const ids = Object.keys(selectedPosts);
		if (!ids.length) return;

		const $btn = $(this);
		setButtonLoading($btn, true);

		$.post(wpcbData.ajaxUrl, {
			action: 'wpcb_export_bulk',
			nonce: wpcbData.nonce,
			post_ids: ids
		}).done(function (response) {
			setButtonLoading($btn, false);
			updateSelectedCount();

			if (response.success) {
				const data = response.data;
				showNotice(
					data.message + ' <a href="' + data.url + '" download><strong>Download (' + formatBytes(data.size) + ')</strong></a>',
					false
				);
				addBackupRow(data);
				selectedPosts = {};
				updateSelectedCount();
			} else {
				showNotice((response.data && response.data.message) || 'Export failed.', true);
			}
		}).fail(function () {
			setButtonLoading($btn, false);
			showNotice('Request failed. Please try again.', true);
		});
	});

	$(document).on('click', '.wpcb-delete-backup', function () {
		const $btn = $(this);
		const filename = $btn.data('filename');

		if (!confirm('Delete backup "' + filename + '"? This cannot be undone.')) {
			return;
		}

		$.post(wpcbData.ajaxUrl, {
			action: 'wpcb_delete_backup',
			nonce: wpcbData.nonce,
			filename: filename
		}).done(function (response) {
			if (response.success) {
				$btn.closest('tr').fadeOut(200, function () { $(this).remove(); });
			} else {
				alert((response.data && response.data.message) || 'Could not delete backup.');
			}
		});
	});

	/* ===================== TABS ===================== */

	$(document).on('click', '.wpcb-tabs .nav-tab', function (e) {
		e.preventDefault();
		const target = $(this).data('tab');

		$('.wpcb-tabs .nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');

		$('.wpcb-tab-panel').hide();
		$('#' + target).show();
	});

	/* ===================== RESTORE FLOW ===================== */

	let restoreFilename = null;
	let lastDomainMismatch = false;

	function resetRestoreSteps() {
		$('#wpcb-restore-step2, #wpcb-restore-step3, #wpcb-restore-results').hide();
		$('#wpcb-restore-db-checkbox, #wpcb-restore-files-checkbox').prop('checked', false);
		lastDomainMismatch = false;
	}

	// Jump to Restore tab and pre-fill an existing backup
	$(document).on('click', '.wpcb-use-for-restore', function () {
		const filename = $(this).data('filename');

		$('.wpcb-tabs .nav-tab[data-tab="wpcb-tab-restore"]').trigger('click');

		restoreFilename = filename;
		$('#wpcb-restore-selected-file').text('Selected: ' + filename);
		resetRestoreSteps();
		inspectBackup(filename);
	});

	// "Set up domain migration now" button inside the inspect-results banner
	$(document).on('click', '#wpcb-jump-to-migrate, #wpcb-jump-to-migrate-after-restore', function () {
		$('.wpcb-tabs .nav-tab[data-tab="wpcb-tab-migrate"]').trigger('click');
	});

	// Upload a new file for restore
	$(document).on('click', '#wpcb-upload-restore-btn', function () {
		const $input = $('#wpcb-restore-file-input');
		const file = $input[0].files[0];

		if (!file) {
			showNotice('Please choose a .zip or .sql file first.', true);
			return;
		}

		const $btn = $(this);
		setButtonLoading($btn, true);
		resetRestoreSteps();

		const formData = new FormData();
		formData.append('action', 'wpcb_upload_backup');
		formData.append('nonce', wpcbData.nonce);
		formData.append('backup_file', file);

		$.ajax({
			url: wpcbData.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false
		}).done(function (response) {
			setButtonLoading($btn, false);

			if (response.success) {
				restoreFilename = response.data.filename;
				$('#wpcb-restore-selected-file').text('Uploaded: ' + restoreFilename);
				inspectBackup(restoreFilename);
			} else {
				showNotice((response.data && response.data.message) || 'Upload failed.', true);
			}
		}).fail(function () {
			setButtonLoading($btn, false);
			showNotice('Upload failed. The file may be too large for this server\'s upload limit.', true);
		});
	});

	function inspectBackup(filename) {
		$('#wpcb-restore-step2').show();
		$('#wpcb-restore-inspect-results').html('<p class="wpcb-hint">Inspecting…</p>');

		$.post(wpcbData.ajaxUrl, {
			action: 'wpcb_inspect_backup',
			nonce: wpcbData.nonce,
			filename: filename
		}).done(function (response) {
			if (!response.success) {
				$('#wpcb-restore-inspect-results').html('<p class="wpcb-hint">' + ((response.data && response.data.message) || 'Could not inspect file.') + '</p>');
				return;
			}

			const d = response.data;
			let html = '';

			// Domain comparison banner — shown first, before the content checklist,
			// since it's the thing most likely to bite someone restoring a backup
			// from another site.
			if (d.domain_detected) {
				if (d.domain_mismatch) {
					html += '<div class="wpcb-domain-banner wpcb-domain-mismatch">' +
						'<strong>⚠️ This backup is from a different site.</strong><br>' +
						'Backup site: <code>' + escapeHtml(d.backup_site_url) + '</code><br>' +
						'This site: <code>' + escapeHtml(d.current_site_url) + '</code><br>' +
						'After restoring, go to the <strong>Migrate Domain</strong> tab to update internal links, ' +
						'image URLs, and settings throughout the database — otherwise pages, posts, and images ' +
						'will still point at the old domain and your site may not run correctly.' +
						' <button type="button" class="button button-small" id="wpcb-jump-to-migrate">Set up domain migration now</button>' +
						'</div>';
				} else {
					html += '<div class="wpcb-domain-banner wpcb-domain-match">' +
						'<strong>✓ This backup matches the current site\'s domain</strong> (' + escapeHtml(d.backup_site_url) + '). ' +
						'No domain migration should be needed after restoring.' +
						'</div>';
				}
			} else if (d.has_database) {
				html += '<div class="wpcb-domain-banner wpcb-domain-unknown">' +
					'<strong>ℹ️ Could not detect the original site\'s domain from this backup.</strong> ' +
					'If this backup came from a different site than <code>' + escapeHtml(d.current_site_url) + '</code>, ' +
					'restore it first, then use the <strong>Migrate Domain</strong> tab and enter the old domain manually.' +
					'</div>';
			}

			html += '<div class="wpcb-inspect-row">' + (d.has_database ? '<span class="wpcb-inspect-yes">✓</span> Database dump found' : '<span class="wpcb-inspect-no">—</span> No database dump') + '</div>';
			html += '<div class="wpcb-inspect-row">' + (d.has_wp_content ? '<span class="wpcb-inspect-yes">✓</span> wp-content files found (themes/plugins/uploads)' : '<span class="wpcb-inspect-no">—</span> No wp-content files') + '</div>';
			html += '<div class="wpcb-inspect-row">' + (d.has_uploads ? '<span class="wpcb-inspect-yes">✓</span> Media/uploads files found' : '<span class="wpcb-inspect-no">—</span> No standalone uploads folder') + '</div>';

			if (d.manifest && d.manifest.generated_at) {
				html += '<div class="wpcb-inspect-row"><strong>Backed up:</strong> ' + escapeHtml(d.manifest.generated_at) + '</div>';
			}

			$('#wpcb-restore-inspect-results').html(html);
			lastDomainMismatch = !!d.domain_mismatch;

			// Pre-fill the Migrate Domain tab fields if we detected a mismatch,
			// so the user doesn't have to type the old URL themselves.
			if (d.backup_site_url) {
				$('#wpcb-old-url').val(d.backup_site_url);
			}
			if (d.current_site_url) {
				$('#wpcb-new-url').val(d.current_site_url);
			}

			// Pre-check sensible defaults based on what's available
			$('#wpcb-restore-db-checkbox').prop('checked', d.has_database);
			$('#wpcb-restore-files-checkbox').prop('checked', d.has_wp_content || d.has_uploads);
			$('#wpcb-restore-files-mode').val(d.has_wp_content ? 'full' : 'media');

			$('#wpcb-restore-step3').show();
		}).fail(function () {
			$('#wpcb-restore-inspect-results').html('<p class="wpcb-hint">Inspection request failed.</p>');
		});
	}

	$(document).on('click', '#wpcb-run-restore-btn', function () {
		if (!restoreFilename) {
			showNotice('No backup file selected.', true);
			return;
		}

		const restoreDb = $('#wpcb-restore-db-checkbox').is(':checked');
		const restoreFiles = $('#wpcb-restore-files-checkbox').is(':checked');

		if (!restoreDb && !restoreFiles) {
			showNotice('Select at least one of "Restore database" or "Restore files".', true);
			return;
		}

		if (restoreDb && !confirm('This will OVERWRITE your current database content with the backup. Continue?')) {
			return;
		}

		const $btn = $(this);
		setButtonLoading($btn, true);
		$('#wpcb-restore-results').show();
		$('#wpcb-restore-log').html('<p class="wpcb-hint">Restoring… this can take a while for large sites, please don\'t close this tab.</p>');

		$.post(wpcbData.ajaxUrl, {
			action: 'wpcb_restore_backup',
			nonce: wpcbData.nonce,
			filename: restoreFilename,
			restore_database: restoreDb ? '1' : '0',
			restore_files: restoreFiles ? '1' : '0',
			files_mode: $('#wpcb-restore-files-mode').val(),
			safety_backup: $('#wpcb-restore-safety-checkbox').is(':checked') ? '1' : '0'
		}).done(function (response) {
			setButtonLoading($btn, false);

			const payload = response.data || {};
			let html = '';

			(payload.log || []).forEach(function (line) {
				html += '<div class="wpcb-log-line">' + escapeHtml(line) + '</div>';
			});

			if (response.success) {
				html += '<div class="wpcb-log-line"><strong>✓ ' + escapeHtml(payload.message || 'Done.') + '</strong></div>';

				if (lastDomainMismatch && payload.db_restored) {
					html += '<div class="wpcb-domain-banner wpcb-domain-mismatch">' +
						'<strong>Next step needed:</strong> this backup was from a different domain. ' +
						'Your database now contains links and settings still pointing at the OLD domain. ' +
						'Go to <strong>Migrate Domain</strong> to fix internal links, image URLs, and site settings.' +
						' <button type="button" class="button button-primary button-small" id="wpcb-jump-to-migrate-after-restore">Go to Migrate Domain</button>' +
						'</div>';
				} else if (payload.current_site_url) {
					html += '<div class="wpcb-log-line">Current site URL is now: <code>' + escapeHtml(payload.current_site_url) + '</code>. If this backup came from a different domain, use the <strong>Migrate Domain</strong> tab next.</div>';
				}

				showNotice('Restore completed successfully.', false);
			} else {
				html += '<div class="wpcb-log-line"><strong>Restore finished with errors.</strong></div>';
				if (payload.db_errors && payload.db_errors.length) {
					html += '<p>' + payload.db_errors_total + ' database error(s) occurred. First ' + payload.db_errors.length + ' shown:</p>';
					payload.db_errors.forEach(function (err) {
						html += '<div class="wpcb-log-error">' + escapeHtml(err.error) + '\n→ ' + escapeHtml(err.statement) + '</div>';
					});
				}
				showNotice((payload.message || 'Restore failed.'), true);
			}

			$('#wpcb-restore-log').html(html);
		}).fail(function () {
			setButtonLoading($btn, false);
			$('#wpcb-restore-log').html('<p class="wpcb-hint">Restore request failed unexpectedly. Check your server error log.</p>');
			showNotice('Restore request failed.', true);
		});
	});

	/* ===================== MIGRATE DOMAIN ===================== */

	function getMigrateValues() {
		return {
			search: $('#wpcb-old-url').val().trim(),
			replace: $('#wpcb-new-url').val().trim()
		};
	}

	function renderReplaceReport(data) {
		let html = '<p>' + escapeHtml(data.message) + '</p>';

		if (data.variants_checked && data.variants_checked.length > 1) {
			html += '<p class="wpcb-hint">Checked variants: ' + data.variants_checked.map(escapeHtml).join(', ') + '</p>';
		}
		if (data.variants_matched && data.variants_matched.length) {
			html += '<p class="wpcb-hint">Found and replaced: <strong>' + data.variants_matched.map(escapeHtml).join('</strong>, <strong>') + '</strong></p>';
		}

		if (data.tables && data.tables.length) {
			html += '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Table</th><th>Rows affected</th><th>Occurrences</th></tr></thead><tbody>';
			data.tables.forEach(function (t) {
				html += '<tr><td>' + escapeHtml(t.table) + '</td><td>' + t.rows + '</td><td>' + t.changes + '</td></tr>';
			});
			html += '</tbody></table>';
		}

		if (data.current_site_url) {
			html += '<p>Site URL option updated to: <code>' + escapeHtml(data.current_site_url) + '</code></p>';
		}

		return html;
	}

	$(document).on('click', '#wpcb-preview-replace-btn', function () {
		const vals = getMigrateValues();
		if (!vals.search || !vals.replace) {
			showNotice('Please fill in both the old and new URL fields.', true);
			return;
		}

		const $btn = $(this);
		setButtonLoading($btn, true);
		$('#wpcb-replace-results').html('<p class="wpcb-hint">Scanning database…</p>');

		$.post(wpcbData.ajaxUrl, {
			action: 'wpcb_preview_search_replace',
			nonce: wpcbData.nonce,
			search: vals.search,
			replace: vals.replace
		}).done(function (response) {
			setButtonLoading($btn, false);

			if (response.success) {
				$('#wpcb-replace-results').html(renderReplaceReport(response.data));
				$('#wpcb-run-replace-btn').prop('disabled', response.data.total_changes === 0);
			} else {
				$('#wpcb-replace-results').html('<p class="wpcb-hint">' + ((response.data && response.data.message) || 'Preview failed.') + '</p>');
			}
		}).fail(function () {
			setButtonLoading($btn, false);
			$('#wpcb-replace-results').html('<p class="wpcb-hint">Preview request failed.</p>');
		});
	});

	$(document).on('click', '#wpcb-run-replace-btn', function () {
		const vals = getMigrateValues();

		if (!confirm('This will permanently rewrite "' + vals.search + '" to "' + vals.replace + '" throughout the database. Make sure you have a backup. Continue?')) {
			return;
		}

		const $btn = $(this);
		setButtonLoading($btn, true);

		$.post(wpcbData.ajaxUrl, {
			action: 'wpcb_run_search_replace',
			nonce: wpcbData.nonce,
			search: vals.search,
			replace: vals.replace
		}).done(function (response) {
			setButtonLoading($btn, false);

			if (response.success) {
				$('#wpcb-replace-results').html(renderReplaceReport(response.data));
				showNotice('Domain replacement complete. You may need to log in again if your site URL changed.', false);
				$btn.prop('disabled', true);
			} else {
				showNotice((response.data && response.data.message) || 'Replacement failed.', true);
			}
		}).fail(function () {
			setButtonLoading($btn, false);
			showNotice('Replacement request failed.', true);
		});
	});

})(jQuery);
