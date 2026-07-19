(function ($) {
	'use strict';

	var pollTimer = null;
	var pollInFlight = false;

	function i18n(key, fallback) {
		return (window.pivAdmin && pivAdmin.i18n && pivAdmin.i18n[key]) || fallback;
	}

	function formatCoverageLabel(percent, verified, eligible) {
		var template = i18n('coverageLabel', '%1$d%% הושלמו (%2$d מתוך %3$d)');
		return template
			.replace('%1$d', String(percent))
			.replace('%2$d', String(verified))
			.replace('%3$d', String(eligible));
	}

	function updateCoverageUI(coverage, $scope) {
		if (!coverage || !coverage.stats) {
			return;
		}

		var stats = coverage.stats;
		var percent = coverage.percent || 0;
		var active = !!coverage.active;
		var $root = $scope && $scope.length ? $scope : $(document);

		$root.find('[data-piv-coverage-progress]').each(function () {
			var $progress = $(this);
			$progress.toggleClass('is-active', active);
			$progress.find('.piv-coverage-progress__bar').css('width', percent + '%');
			$progress.find('[data-piv-coverage-label]').text(
				formatCoverageLabel(percent, stats.verified || 0, stats.eligible || 0)
			);
			$progress.find('[data-piv-coverage-status]').text(
				active ? i18n('liveUpdating', 'מתעדכן בזמן אמת…') : i18n('liveComplete', 'הבדיקה הושלמה')
			);
		});

		$root.find('[data-piv-coverage-stats]').each(function () {
			var $list = $(this);
			$list.find('[data-stat="eligible"]').text(stats.eligible || 0);
			$list.find('[data-stat="verified"]').text(stats.verified || 0);
			$list.find('[data-stat="unverified"]').text(stats.unverified || 0);
			$list.find('[data-stat="queue"]').text(stats.queue || 0);
		});
	}

	function renderStatusCell(row) {
		if (row.excluded) {
			return '<span class="piv-report-badge piv-report-badge--none">' + escapeHtml(i18n('excluded', 'מוחרג (ביקורת/דעה)')) + '</span>';
		}

		if (!row.layer) {
			return '<span class="piv-report-badge piv-report-badge--none">' + escapeHtml(i18n('notChecked', 'טרם נבדק')) + '</span>';
		}

		return (
			'<span class="piv-report-badge piv-report-badge--' + escapeHtml(row.layer) + '" style="--piv-color:' + escapeHtml(row.layer_color) + '">' +
				'<span aria-hidden="true">' + escapeHtml(row.layer_icon) + '</span>' +
				escapeHtml(row.layer_label) +
			'</span>'
		);
	}

	function renderPercentCell(row) {
		if (row.percent === null || typeof row.percent === 'undefined') {
			return '—';
		}

		var width = Math.min(100, parseInt(row.percent, 10) || 0);
		return (
			'<div class="piv-report-percent">' +
				'<div class="piv-report-percent__bar" style="width:' + width + '%;"></div>' +
				'<span class="piv-report-percent__value">' + width + '%</span>' +
			'</div>'
		);
	}

	function renderSourcesCell(row) {
		if (!row.source_items || !row.source_items.length) {
			return escapeHtml(String(row.sources || 0));
		}

		var countLabel = row.source_items.length === 1
			? i18n('sourcesOne', 'מקור אחד')
			: i18n('sourcesMany', '%d מקורות').replace('%d', String(row.source_items.length));

		var html = '<span class="piv-report-sources-count">' + escapeHtml(countLabel) + '</span><ul class="piv-report-sources-list">';
		row.source_items.forEach(function (source) {
			html += '<li><a href="' + escapeHtml(source.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(source.label) + '</a></li>';
		});
		html += '</ul>';
		return html;
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function updateReportRow($row, row) {
		$row.attr('data-layer', row.layer || '');
		$row.toggleClass('piv-report-row--excluded', !!row.excluded);
		$row.find('[data-piv-cell="status"]').html(renderStatusCell(row));
		$row.find('[data-piv-cell="percent"]').html(renderPercentCell(row));
		$row.find('[data-piv-cell="sources"]').html(renderSourcesCell(row));
		$row.find('[data-piv-cell="checked"]').text(row.checked_at_label || '—');
		$row.find('[data-piv-cell="reason"]').text(row.reason || '—');
	}

	function shouldKeepPolling(coverage) {
		if (!coverage || !coverage.stats) {
			return false;
		}

		if (coverage.active) {
			return true;
		}

		if ($('[data-piv-live-report]').length && $('.piv-report-row[data-post-id]').length) {
			var hasPendingRows = false;
			$('.piv-report-row[data-post-id]').each(function () {
				var layer = $(this).attr('data-layer');
				if (!layer || layer === 'under_review' || layer === 'initial_report') {
					hasPendingRows = true;
					return false;
				}
			});
			return hasPendingRows;
		}

		return !!$('[data-piv-live-coverage]').length && (coverage.stats.queue > 0 || coverage.stats.unverified > 0);
	}

	function pollLiveUpdates(force) {
		if (!window.pivAdmin || pollInFlight) {
			return;
		}

		var postIds = [];
		$('.piv-report-row[data-post-id]').each(function () {
			var id = parseInt($(this).attr('data-post-id'), 10);
			if (id) {
				postIds.push(id);
			}
		});

		var payload = {
			action: 'piv_get_coverage_stats',
			nonce: pivAdmin.nonce,
			force: force ? 1 : 0
		};

		if (pivAdmin.page === 'report' && postIds.length) {
			payload.action = 'piv_get_report_rows';
			payload.post_ids = postIds;
			payload.force = force ? 1 : 0;
		}

		pollInFlight = true;

		$.post(pivAdmin.ajaxUrl, payload)
			.done(function (response) {
				if (!response || !response.success || !response.data) {
					return;
				}

				if (response.data.coverage) {
					updateCoverageUI(response.data.coverage);
				} else if (response.data.stats) {
					updateCoverageUI(response.data);
				}

				if (response.data.rows) {
					Object.keys(response.data.rows).forEach(function (postId) {
						var row = response.data.rows[postId];
						var $target = $('.piv-report-row[data-post-id="' + postId + '"]');
						if ($target.length) {
							updateReportRow($target, row);
						}
					});
				}

				schedulePoll(shouldKeepPolling(response.data.coverage || response.data));
			})
			.always(function () {
				pollInFlight = false;
			});
	}

	function schedulePoll(keepGoing) {
		if (pollTimer) {
			window.clearTimeout(pollTimer);
			pollTimer = null;
		}

		if (!keepGoing || !window.pivAdmin) {
			return;
		}

		pollTimer = window.setTimeout(function () {
			pollLiveUpdates(true);
		}, pivAdmin.pollInterval || 3000);
	}

	function initLiveUpdates() {
		if (!$('[data-piv-live-coverage]').length && !$('[data-piv-live-report]').length) {
			return;
		}

		schedulePoll(true);
	}

	function setPostButtonsDisabled(disabled) {
		$('#piv-recheck-btn, #piv-reset-recheck-btn, #piv-precision-scan-btn').prop('disabled', !!disabled);
	}

	function resetPostButtonLabels() {
		$('#piv-recheck-btn').text('בדוק שוב עכשיו');
		$('#piv-reset-recheck-btn').text('אפס ובדוק מחדש');
		$('#piv-precision-scan-btn').text('סריקה מדויקת מלאה');
	}

	function runPostRecheck(options) {
		options = options || {};
		var reset = !!options.reset;
		var precision = !!options.precision;
		var $box = $('#piv-metabox');
		var postId = $box.data('post-id');
		var $recheck = $('#piv-recheck-btn');
		var $resetBtn = $('#piv-reset-recheck-btn');
		var $precisionBtn = $('#piv-precision-scan-btn');

		if (!postId || !window.pivAdmin) {
			return;
		}

		if (precision) {
			if (!window.confirm(i18n('confirmPrecision', 'להריץ סריקה מדויקת מלאה? זה יכול לקחת כמה דקות.'))) {
				return;
			}
			reset = true;
		} else if (reset && !window.confirm(i18n('confirmReset', 'לאפס את תוצאת האימות ולסרוק מחדש?'))) {
			return;
		}

		setPostButtonsDisabled(true);

		var busyLabel = precision
			? i18n('precisionScan', 'מריץ סריקה מדויקת מלאה...')
			: (reset
				? i18n('resetting', 'מאפס ובודק מחדש...')
				: i18n('checking', 'סורק את כל האתרים...'));

		if (precision) {
			$precisionBtn.text(busyLabel);
		} else if (reset) {
			$resetBtn.text(busyLabel);
		} else {
			$recheck.text(busyLabel);
		}

		$.ajax({
			url: pivAdmin.ajaxUrl,
			method: 'POST',
			timeout: 360000,
			data: {
				action: 'piv_recheck_post',
				nonce: pivAdmin.nonce,
				post_id: postId,
				reset: reset ? 1 : 0,
				precision: precision ? 1 : 0
			}
		})
			.done(function (response) {
				if (!response || !response.success) {
					window.alert(i18n('error', 'שגיאה בבדיקה'));
					return;
				}

				window.location.reload();
			})
			.fail(function () {
				window.alert(i18n('error', 'שגיאה בבדיקה'));
			})
			.always(function () {
				setPostButtonsDisabled(false);
				resetPostButtonLabels();
			});
	}

	$(document).on('click', '#piv-recheck-btn', function () {
		runPostRecheck({ reset: false, precision: false });
	});

	$(document).on('click', '#piv-reset-recheck-btn', function () {
		runPostRecheck({ reset: true, precision: false });
	});

	$(document).on('click', '#piv-precision-scan-btn', function () {
		runPostRecheck({ reset: true, precision: true });
	});

	$(document).on('click', '#piv-enqueue-all-btn', function () {
		var $btn = $(this);
		var $status = $('#piv-enqueue-all-status');
		var $resetAll = $('#piv-reset-all-btn');

		if (!window.pivAdmin) {
			return;
		}

		$btn.prop('disabled', true);
		$resetAll.prop('disabled', true);
		$status.text(i18n('queueing', 'מוסיף לתור...'));

		$.post(pivAdmin.ajaxUrl, {
			action: 'piv_enqueue_all_posts',
			nonce: pivAdmin.nonce
		})
			.done(function (response) {
				if (!response || !response.success) {
					window.alert(i18n('queueError', 'שגיאה בהוספה לתור'));
					return;
				}

				var message = response.data && response.data.message ? response.data.message : i18n('queueDone', 'הפוסטים נוספו לתור הבדיקה');
				$status.text(message);

				if (response.data && response.data.coverage) {
					updateCoverageUI(response.data.coverage);
					schedulePoll(true);
				}
			})
			.fail(function () {
				window.alert(i18n('queueError', 'שגיאה בהוספה לתור'));
			})
			.always(function () {
				$btn.prop('disabled', false);
				$resetAll.prop('disabled', false);
			});
	});

	$(document).on('click', '#piv-reset-all-btn', function () {
		var $btn = $(this);
		var $status = $('#piv-enqueue-all-status');
		var $enqueue = $('#piv-enqueue-all-btn');

		if (!window.pivAdmin) {
			return;
		}

		if (!window.confirm(i18n('confirmResetAll', 'לאפס עכשיו את כל תוצאות האימות באתר?'))) {
			return;
		}

		$btn.prop('disabled', true);
		$enqueue.prop('disabled', true);
		$status.text(i18n('resettingAll', 'מאפס את כל האימותים...'));

		$.post(pivAdmin.ajaxUrl, {
			action: 'piv_reset_all_verifications',
			nonce: pivAdmin.nonce
		})
			.done(function (response) {
				if (!response || !response.success) {
					window.alert(i18n('resetAllError', 'שגיאה באיפוס הכללי'));
					return;
				}

				var message = response.data && response.data.message
					? response.data.message
					: i18n('resetAllDone', 'האיפוס הכללי הושלם');
				$status.text(message);

				if (response.data && response.data.coverage) {
					updateCoverageUI(response.data.coverage);
					schedulePoll(true);
				}
			})
			.fail(function () {
				window.alert(i18n('resetAllError', 'שגיאה באיפוס הכללי'));
			})
			.always(function () {
				$btn.prop('disabled', false);
				$enqueue.prop('disabled', false);
			});
	});

	$(document).on('click', '#piv-test-connections-btn', function () {
		var $btn = $(this);
		var $out = $('#piv-test-connections-results');

		if (!window.pivAdmin) {
			return;
		}

		var labels = {
			google: 'Google Custom Search',
			gemini: 'Gemini AI',
			google_news: 'Google News RSS',
			loopback: 'עיבוד רקע (Loopback)',
			wp_cron: 'WP-Cron'
		};

		$btn.prop('disabled', true);
		$out.html('<p>בודק חיבורים... (עד 30 שניות)</p>');

		$.post(pivAdmin.ajaxUrl, {
			action: 'piv_test_connections',
			nonce: pivAdmin.nonce
		})
			.done(function (response) {
				if (!response || !response.success || !response.data || !response.data.results) {
					$out.html('<p style="color:#b32d2e">שגיאה בהרצת בדיקת החיבורים</p>');
					return;
				}

				var html = '<ul style="margin-top:8px">';
				$.each(response.data.results, function (key, row) {
					var icon = row.ok ? '✅' : '❌';
					var name = labels[key] || key;
					html += '<li>' + icon + ' <strong>' + name + ':</strong> ' +
						$('<span>').text(row.message || '').html() + '</li>';
				});
				html += '</ul>';
				$out.html(html);
			})
			.fail(function () {
				$out.html('<p style="color:#b32d2e">שגיאה בהרצת בדיקת החיבורים</p>');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	$(initLiveUpdates);
})(jQuery);
