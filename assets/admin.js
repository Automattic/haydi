/**
 * Haydi — Admin JavaScript
 *
 * Handles:
 *  - AI chat (send message → agentic loop → render response)
 *  - Proposal UI (diff/SQL/PHP/etc. → approve/reject), shown only while one is active
 *  - Audit log clear
 *
 * Requires: jQuery (WordPress bundled), haydi.ajaxUrl, haydi.nonce
 */

/* global haydi, jQuery, _tkq */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    var state = {
        messages:       [],     // Full messages array (chat history).
        displayLog:     [],     // Visible chat as {role, text} entries — saved verbatim.
        // Single outstanding approval proposal: { kind, tool_use_id, pre_results, ...payload } or null.
        // Server design guarantees at most one is active at a time.
        pending:        null,
        busy:           false,  // Prevent concurrent requests.
        xhr:            null,   // Current in-flight AJAX request (for abort).
        applyInFlight:  false,  // True while an approval AJAX (Apply / Execute / etc.) is in flight.
        autoAccept:     false,  // When true, every incoming proposal is applied without prompting.
        chatTitle:       null,
        chatTitleSource: 'fallback',
        titleGenerationStatus:   'idle', // idle | pending | done | failed.
    };

    var attachments = []; // [{name, content}] — cleared after each send.
    var MAX_ATTACHMENT_BYTES = 200 * 1024;

    function renderAttachments() {
        var $area = $('#wpc-attachments').empty().toggleClass('wpc-hidden', !attachments.length);
        attachments.forEach(function (att, idx) {
            $area.append(
                '<span class="wpc-attachment">'
                + '<span class="dashicons dashicons-media-text"></span>'
                + esc(att.name)
                + '<button type="button" class="wpc-attachment-remove" data-idx="' + idx + '" aria-label="Remove">&times;</button>'
                + '</span>'
            );
        });
    }

    $('#wpc-btn-attach').on('click', function () {
        $('#wpc-attach-input').trigger('click');
    });

    $('#wpc-attach-input').on('change', function () {
        var files = this.files;
        var remaining = files.length;
        if (!remaining) { return; }
        Array.prototype.forEach.call(files, function (file) {
            if (file.size > MAX_ATTACHMENT_BYTES) {
                alert(file.name + ' is too large (max 200 KB).');
                remaining--;
                return;
            }
            var reader = new FileReader();
            reader.onload = function (e) {
                attachments.push({ name: file.name, content: e.target.result });
                renderAttachments();
            };
            reader.readAsText(file);
        });
        this.value = '';
    });

    $('#wpc-attachments').on('click', '.wpc-attachment-remove', function () {
        attachments.splice(Number($(this).data('idx')), 1);
        renderAttachments();
    });

    // Returns true if the action should proceed.  In auto-accept mode the
    // browser's confirm() dialog is bypassed so proposals chain end-to-end.
    function maybeConfirm(msg) {
        return state.autoAccept || confirm(msg);
    }

    // -------------------------------------------------------------------------
    // Tooltips
    // -------------------------------------------------------------------------

    var $activeTip = null;
    var $floatingTooltip = $('<div class="wpc-floating-tooltip" role="tooltip"></div>').appendTo(document.body);

    function positionFloatingTooltip() {
        if (!$activeTip || !$activeTip.length) { return; }

        var tip = $activeTip[0].getBoundingClientRect();
        var tooltip = $floatingTooltip[0];
        var gap = 8;
        var margin = 8;
        var tooltipWidth = tooltip.offsetWidth || 200;
        var tooltipHeight = tooltip.offsetHeight || 0;
        var left = tip.right - tooltipWidth;
        var top = tip.top - tooltipHeight - gap;

        left = Math.max(margin, Math.min(left, window.innerWidth - tooltipWidth - margin));

        if (top < margin) {
            top = tip.bottom + gap;
        }
        top = Math.max(margin, Math.min(top, window.innerHeight - tooltipHeight - margin));

        $floatingTooltip.css({
            left: Math.round(left) + 'px',
            top:  Math.round(top) + 'px',
        });
    }

    function showFloatingTooltip(el) {
        var $tip = $(el);
        var text = $tip.attr('data-tip');

        if (!text) { return; }

        $activeTip = $tip;
        $floatingTooltip
            .text(text)
            .addClass('is-visible');
        positionFloatingTooltip();
    }

    function hideFloatingTooltip() {
        if ($activeTip) {
            $activeTip = null;
        }
        $floatingTooltip.removeClass('is-visible');
    }

    $(document)
        .on('mouseenter focusin', '.wpc-session-stats .wpc-tip', function () {
            showFloatingTooltip(this);
        })
        .on('mouseleave focusout', '.wpc-session-stats .wpc-tip', function () {
            hideFloatingTooltip();
        })
        .on('keydown', function (e) {
            if (e.key === 'Escape') {
                hideFloatingTooltip();
            }
        });

    $(window).on('scroll resize', function () {
        positionFloatingTooltip();
    });

    $('#wpc-sidebar').on('scroll', function () {
        positionFloatingTooltip();
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function timeAgo(unixSeconds) {
        var diff = Math.floor(Date.now() / 1000) - unixSeconds;
        if (diff < 60)           { return 'just now'; }
        if (diff < 3600)         { return Math.floor(diff / 60) + 'm ago'; }
        if (diff < 86400)        { return Math.floor(diff / 3600) + 'h ago'; }
        if (diff < 86400 * 2)    { return 'yesterday'; }
        if (diff < 86400 * 7)    { return Math.floor(diff / 86400) + 'd ago'; }
        return new Date(unixSeconds * 1000).toLocaleDateString();
    }

    // -------------------------------------------------------------------------
    // Chat history — persisted server-side via wp_usermeta
    // -------------------------------------------------------------------------

    var currentChatId = null; // ID of the chat currently loaded.
    var selectedModelPreference = null; // { provider, model, label, providerLabel } or null when no configured choices exist.
    var MODEL_PREFERENCE_STORAGE_KEY = 'haydiModelPreference';

    // Reflect the active chat ID in the address bar so a refresh
    // restores the same chat. Other query params (notably ?page=...) are
    // preserved; pass null to drop the chat param entirely.
    function syncChatIdToUrl(id) {
        var params = new URLSearchParams(window.location.search);
        if (id) {
            params.set('chat', id);
        } else {
            params.delete('chat');
        }
        var qs  = params.toString();
        var url = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
        window.history.replaceState({ chatId: id || null }, '', url);
    }

    // -------------------------------------------------------------------------
    // Metrics — Automattic Tracks
    // -------------------------------------------------------------------------

    if (haydi.tracksEnabled) {
        window._tkq = window._tkq || [];
        if (haydi.wpcomUserId) {
            _tkq.push(['identifyUser', haydi.wpcomUserId, haydi.userLogin]);
        }
    }

    var sessionApplyCount = 0;

    // Cumulative token usage for this browser session — prompt/completion/total
    // are summed across every successful chat response. prompt_peak/prompt_last
    // track single-request context pressure instead of cost. Resets on page
    // reload and on "New chat".
    var sessionUsage = { prompt: 0, completion: 0, total: 0, prompt_peak: 0, prompt_last: 0, model: '' };
    var AUTO_COMPACT_CONTEXT_THRESHOLD = 0.90;

    function formatTokens(n) {
        n = Number(n) || 0;
        if (n < 1000)    return String(n);
        if (n < 1000000) return (n / 1000).toFixed(n < 10000 ? 1 : 0) + 'k';
        return (n / 1000000).toFixed(1) + 'M';
    }

    function updateUsageBadge() {
        $('#wpc-metric-session-tokens').text(formatTokens(sessionUsage.total));
        var title = sessionUsage.prompt.toLocaleString() + ' in / ' +
                    sessionUsage.completion.toLocaleString() + ' out';
        if (sessionUsage.model) { title += ' · ' + sessionUsage.model; }
        $('#wpc-session-tokens-badge').attr('title', title);
    }

    function findModelLimit(model) {
        if (!model) return null;
        var limits = haydi.modelLimits || {};
        var providerIds = Object.keys(limits);
        var lowerModel = String(model).toLowerCase();

        for (var i = 0; i < providerIds.length; i++) {
            var provider = limits[providerIds[i]] || {};
            var models = provider.models || {};
            if (models[model]) return models[model];

            var modelIds = Object.keys(models);
            for (var j = 0; j < modelIds.length; j++) {
                if (modelIds[j].toLowerCase() === lowerModel) {
                    return models[modelIds[j]];
                }
            }
        }
        return null;
    }

    function getReservedOutputTokens() {
        var configured = Number($('#wpc-max-tokens').val()) || Number(haydi.maxTokens) || 0;
        return Math.max(0, configured);
    }

    function updateContextBadge() {
        var peak = Number(sessionUsage.prompt_peak) || 0;
        var last = Number(sessionUsage.prompt_last) || 0;
        var current = last || peak;
        var model = sessionUsage.model || '';
        var limitInfo = findModelLimit(model);
        var maxInput = limitInfo && Number(limitInfo.max_input_tokens);
        var reservedOutput = getReservedOutputTokens();
        var usableInput = maxInput ? Math.max(1, maxInput - reservedOutput) : 0;
        var $badge = $('#wpc-context-badge').show().removeClass('is-warn is-danger');

        if (!current) {
            $('#wpc-metric-context').text('--');
            $badge.attr('title', 'Context size will appear after the first model response.');
            return;
        }

        if (!maxInput) {
            $badge.hide();
            return;
        }

        var pct = Math.min(999, Math.round((current / usableInput) * 100));
        $('#wpc-metric-context').text(pct + '%');
        if (pct >= 80) {
            $badge.addClass('is-danger');
        } else if (pct >= 60) {
            $badge.addClass('is-warn');
        }

        var title = 'Latest prompt: ' + current.toLocaleString() + ' / ' +
                    usableInput.toLocaleString() + ' usable input tokens (' + pct + '%)';
        if (reservedOutput) { title += ' · reserves ' + reservedOutput.toLocaleString() + ' output'; }
        if (peak && peak !== current) { title += ' · peak: ' + peak.toLocaleString(); }
        if (model) { title += ' · ' + model; }
        $badge.attr('title', title);
    }

    function currentContextPressure() {
        var current = Number(sessionUsage.prompt_last) || Number(sessionUsage.prompt_peak) || 0;
        var limitInfo = findModelLimit(sessionUsage.model || '');
        var maxInput = limitInfo && Number(limitInfo.max_input_tokens);
        var usableInput = maxInput ? Math.max(1, maxInput - getReservedOutputTokens()) : 0;

        if (!current || !usableInput) { return 0; }
        return current / usableInput;
    }

    function shouldAutoCompactBeforeSend() {
        if (state.applyInFlight || state.busy || state.pending || !state.messages.length) { return false; }
        return currentContextPressure() >= AUTO_COMPACT_CONTEXT_THRESHOLD;
    }

    // Inline SVG icons for the Copy button — Lucide-style 14px clipboard /
    // checkmark glyphs. Inlined (rather than dashicons) so they inherit
    // currentColor and stay legible inside the muted timestamp line.
    var COPY_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    var CHECK_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    var SEND_ICON_SVG  = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M8 13V3M8 3L4 7M8 3L12 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var STOP_ICON_SVG  = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="10" height="10" rx="2" fill="currentColor"/></svg>';

    // Copy arbitrary text via the async Clipboard API, falling back to a
    // hidden-textarea + execCommand on older browsers / non-secure contexts.
    // Briefly swaps the clipboard icon for a checkmark on success.
    function copyToClipboard(text, $btn) {
        var done = function () {
            $btn.html(CHECK_ICON_SVG).addClass('is-copied').prop('disabled', true);
            window.setTimeout(function () {
                $btn.html(COPY_ICON_SVG).removeClass('is-copied').prop('disabled', false);
            }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () {
                // Clipboard rejection is rare (e.g. permissions-policy); do
                // nothing visible rather than mislead the user with a check.
            });
            return;
        }
        var $ta = $('<textarea>').css({ position: 'fixed', top: '-9999px' }).val(text).appendTo('body');
        $ta[0].select();
        try { document.execCommand('copy'); } catch (_e) { /* best effort */ }
        $ta.remove();
        done();
    }

    // Append the per-turn token usage onto the most recent assistant
    // timestamp, so the line reads e.g. "10:24 · [copy] · 1,200 in · 350 out · …".
    // The Copy button sits immediately after the timestamp (added by
    // renderMessage) and the usage trails it. Falls back to a standalone
    // caption for legacy entries that have no timestamp to attach to.
    function renderUsageCaption(text) {
        var $time = $('#wpc-chat-messages').find('.wpc-message-time--assistant').last();
        if ($time.length) {
            $time.append(document.createTextNode(' · '));
            $time.append($('<span class="wpc-message-usage"></span>').text(text));
        } else {
            $('<div class="wpc-message-usage"></div>').text(text).appendTo('#wpc-chat-messages');
        }
        scrollChatToBottom();
    }

    // Append a small caption under the most recent assistant bubble describing
    // the tokens consumed by that turn. Called once per chat response. The
    // caption is also pushed into displayLog so it survives reRenderChat()
    // when the chat is reopened later.
    function appendTurnUsage(usage) {
        if (!usage || !usage.total) return;
        var label = usage.prompt.toLocaleString() + ' in · ' +
                    usage.completion.toLocaleString() + ' out · ' +
                    usage.total.toLocaleString() + ' total';
        if (usage.model) { label += ' · ' + usage.model; }
        renderUsageCaption(label);
        state.displayLog.push({ role: 'usage', text: label });
    }

    function showToolActivity() {
        return haydi.showToolActivity !== false;
    }

    function normaliseActivityEntries(activity) {
        if (!showToolActivity()) { return []; }
        if (Array.isArray(activity)) { return activity; }
        if (typeof activity !== 'string' || !activity) { return []; }
        try {
            var parsed = JSON.parse(activity);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_e) {
            return [];
        }
    }

    function renderCompactSummary(text) {
        var $details = $('<details class="wpc-compact-summary"></details>')
            .append($('<summary class="wpc-compact-summary__toggle">View summary</summary>'))
            .append($('<div class="wpc-compact-summary__body"></div>').html(renderAssistantMarkdown(text)));
        $('#wpc-chat-messages').append($details);
        scrollChatToBottom();
        return $details;
    }

    function renderToolActivity(activity) {
        var entries = normaliseActivityEntries(activity);
        if (!entries.length) { return null; }

        return $(buildToolActivityHtml(entries)).appendTo('#wpc-chat-messages');
    }

    function summarizeToolActivity(entries) {
        var labels = entries.map(function (entry) {
            return entry && entry.label ? entry.label : 'Used tool';
        });
        var summary = labels.slice(0, 3).join(', ');
        if (labels.length > 3) {
            summary += ' +' + (labels.length - 3);
        }

        return summary;
    }

    function buildToolActivityHtml(entries) {
        var html = '<details class="wpc-tool-activity">'
            + '<summary>'
            + '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>'
            + '<span>Activity</span>'
            + '<span class="wpc-tool-activity__summary">' + esc(summarizeToolActivity(entries)) + '</span>'
            + '</summary>'
            + '<div class="wpc-tool-activity__list">';

        entries.forEach(function (entry) {
            entry = entry || {};
            var status = entry.status === 'error' ? 'error' : 'ok';
            html += '<div class="wpc-tool-activity__item is-' + status + '">'
                + '<span class="wpc-tool-activity__dot" aria-hidden="true"></span>'
                + '<div class="wpc-tool-activity__body">'
                + '<div class="wpc-tool-activity__label">' + esc(entry.label || 'Used tool') + '</div>'
                + '<div class="wpc-tool-activity__detail">' + esc(entry.summary || '') + '</div>'
                + '</div>'
                + '</div>';
        });

        html += '</div></details>';
        return html;
    }

    function appendToolActivity(activity) {
        var entries = normaliseActivityEntries(activity);
        if (!entries.length) { return null; }

        var $activity = renderToolActivity(entries);
        state.displayLog.push({
            role: 'activity',
            text: JSON.stringify(entries),
            t:    Date.now(),
        });
        scrollChatToBottom();
        return $activity;
    }

    function createStreamProgress() {
        var $progress = $(
            '<div class="wpc-stream-progress">'
            + '<div class="wpc-stream-progress__status">'
            + spinner()
            + '<span class="wpc-stream-progress__status-text">Working...</span>'
            + '</div>'
            + '<div class="wpc-stream-progress__activity"></div>'
            + '</div>'
        ).appendTo('#wpc-chat-messages');
        var $status = $progress.find('.wpc-stream-progress__status-text');
        var $activity = $progress.find('.wpc-stream-progress__activity');
        var entries = [];
        var lastStep = '';
        var pendingEntryIndex = null;

        function setStatus(message) {
            $status.text(message || 'Working...');
            scrollChatToBottom();
        }

        function renderActivity() {
            if (!entries.length) {
                $activity.empty();
                return;
            }

            var wasOpen = $activity.find('.wpc-tool-activity').prop('open') === true;
            $activity.html(buildToolActivityHtml(entries));
            if (wasOpen) {
                $activity.find('.wpc-tool-activity').prop('open', true);
            }
        }

        function addStep(label, detail, status, phase) {
            if (!showToolActivity()) { return; }
            label = label || 'Used tool';
            detail = detail || '';
            status = status === 'error' ? 'error' : 'ok';

            var signature = label + '\n' + detail + '\n' + status;
            if (signature === lastStep) { return; }
            lastStep = signature;

            var entry = {
                label:   label,
                summary: detail,
                status:  status,
            };

            if (phase === 'done' && pendingEntryIndex !== null) {
                entries[pendingEntryIndex] = entry;
                pendingEntryIndex = null;
            } else {
                entries.push(entry);
                pendingEntryIndex = phase === 'start' ? entries.length - 1 : null;
            }

            renderActivity();
            scrollChatToBottom();
        }

        return {
            setStatus: setStatus,
            addStep: addStep,
            remove: function () {
                $progress.remove();
            },
        };
    }

    function updateStreamAssistantPreview($preview, text) {
        if (!$.trim(text || '')) { return $preview; }
        if (!$preview || !$preview.length) {
            $preview = $('<div class="wpc-message wpc-message--assistant wpc-stream-assistant"></div>')
                .appendTo('#wpc-chat-messages');
        }
        $preview.html(renderAssistantMarkdown(text));
        scrollChatToBottom();
        return $preview;
    }

    function recordUsage(usage) {
        if (!usage) return;
        sessionUsage.prompt     += Number(usage.prompt)     || 0;
        sessionUsage.completion += Number(usage.completion) || 0;
        sessionUsage.total      += Number(usage.total)      || 0;
        sessionUsage.prompt_peak = Math.max(
            Number(sessionUsage.prompt_peak) || 0,
            Number(usage.prompt_peak || usage.prompt_last || usage.prompt) || 0
        );
        sessionUsage.prompt_last = Number(usage.prompt_last || usage.prompt_peak || usage.prompt) || 0;
        if (usage.model) { sessionUsage.model = usage.model; }
        updateUsageBadge();
        updateContextBadge();
        appendTurnUsage(usage);
    }

    function resetSessionUsage() {
        sessionUsage = { prompt: 0, completion: 0, total: 0, prompt_peak: 0, prompt_last: 0, model: '' };
        updateUsageBadge();
        updateContextBadge();
    }

    function recordEvent(name, props) {
        if (!haydi.tracksEnabled) { return; }
        window._tkq = window._tkq || [];
        // Always include `connected` so we can split events by Jetpack/WP.com link
        // status — anonymous (disconnected) installs still record events, but
        // without an identifyUser they're indistinguishable in Tracks otherwise.
        var baseProps = { connected: !!haydi.wpcomBlogId };
        if (haydi.wpcomBlogId) { baseProps.blog_id = haydi.wpcomBlogId; }
        _tkq.push(['recordEvent', name, Object.assign(baseProps, props || {})]);
    }

    $(document).on('click', '.wpc-track-studio', function () {
        recordEvent('wpadmin_aibuilder_studio_click');
    });

    $(document).on('click', '.wpc-track-jetpack-cta', function () {
        recordEvent('wpadmin_aibuilder_jetpack_cta_click', {
            cta_type: $(this).data('cta-type') || '',
        });
    });

    function recordApply(proposalType) {
        var wasFirst = sessionApplyCount === 0;
        sessionApplyCount++;
        $('#wpc-metric-session-applies').text(sessionApplyCount);
        recordEvent('wpadmin_aibuilder_apply', { proposal_type: proposalType });
        if (wasFirst) {
            recordEvent('wpadmin_aibuilder_session_start');
        }
        if (currentChatId) {
            post('haydi_record_apply', { id: currentChatId }, function () {});
        }
    }

    function resetApplyCounter() {
        sessionApplyCount = 0;
        $('#wpc-metric-session-applies').text(0);
    }

    function fallbackChatTitle() {
        var title = 'Chat';
        for (var i = 0; i < state.messages.length; i++) {
            if (state.messages[i].role === 'user') {
                var c = state.messages[i].content;
                if (typeof c === 'string' && c) { title = c; break; }
            }
        }
        return title.length > 60 ? title.substring(0, 57) + '\u2026' : title;
    }

    function chatTitle() {
        return state.chatTitle || fallbackChatTitle();
    }

    function chatTitleSource() {
        return state.chatTitleSource === 'ai' ? 'ai' : 'fallback';
    }

    function setChatTitle(title, source) {
        state.chatTitle = title || null;
        state.chatTitleSource = source === 'ai' ? 'ai' : 'fallback';
        state.titleGenerationStatus = state.chatTitleSource === 'ai' ? 'done' : 'idle';
    }

    function resetChatTitle() {
        state.chatTitle = null;
        state.chatTitleSource = 'fallback';
        state.titleGenerationStatus = 'idle';
    }

    function hasAssistantResponse() {
        return state.displayLog.some(function (entry) {
            return entry.role === 'assistant' && $.trim(entry.text || '') !== '';
        });
    }

    function maybeGenerateChatTitle() {
        if (!currentChatId) { return; }
        if (state.chatTitleSource === 'ai') { return; }
        if (state.titleGenerationStatus !== 'idle') { return; }
        if (!hasAssistantResponse()) { return; }

        var requestedChatId = currentChatId;
        state.titleGenerationStatus = 'pending';

        post('haydi_generate_chat_title', $.extend({
            id: requestedChatId,
        }, modelPreferencePayload()), function (res) {
            if (requestedChatId !== currentChatId) { return; }
            if (res.success && res.data && res.data.title) {
                setChatTitle(res.data.title, res.data.title_source || 'ai');
                renderChatList();
                return;
            }
            state.titleGenerationStatus = 'failed';
        }).fail(function () {
            if (requestedChatId === currentChatId) {
                state.titleGenerationStatus = 'failed';
            }
        });
    }

    function saveCurrentChatNow(opts) {
        if (!state.messages.length) return;
        var detached = !!(opts && opts.detached);
        post('haydi_save_chat', {
            id:           currentChatId || '',
            title:        chatTitle(),
            title_source: chatTitleSource(),
            messages:     JSON.stringify(state.messages),
            display:      JSON.stringify(state.displayLog),
            usage:        JSON.stringify(sessionUsage),
        }, function (res) {
            // Detached saves (e.g. flushing the previous chat when
            // the user starts a new one) must not claim the local state —
            // currentChatId has already been cleared by the caller.
            if (detached) return;
            if (res.success && !currentChatId) {
                currentChatId = res.data.id;
                syncChatIdToUrl(currentChatId);
                recordEvent('wpadmin_aibuilder_chat_save');
            }
            if (res.success) {
                if (res.data && res.data.title_source === 'ai') {
                    setChatTitle(res.data.title, res.data.title_source);
                }
                renderChatList();
                renderAuditLog();
                maybeGenerateChatTitle();
            }
        });
    }

    // Debounced save: coalesces rapid back-to-back calls into a single
    // network round-trip + user_meta write. Each chat turn triggers two or
    // three save attempts, and serialising hundreds of KB of history each
    // time is wasteful when the next save is milliseconds behind.
    var saveTimer = null;
    function saveCurrentChat() {
        if (saveTimer) { window.clearTimeout(saveTimer); }
        saveTimer = window.setTimeout(function () {
            saveTimer = null;
            saveCurrentChatNow();
        }, 1500);
    }

    // Force any pending debounced save to flush immediately. Used before
    // destructive actions (new chat, page unload) so we never lose the
    // most recent turn just because it was still in the debounce window.
    function flushSave() {
        if (saveTimer) {
            window.clearTimeout(saveTimer);
            saveTimer = null;
            saveCurrentChatNow();
        }
    }

    window.addEventListener('beforeunload', flushSave);

    function onBeforeUnloadBusy(e) {
        e.preventDefault();
    }

    function setBusy(busy) {
        state.busy = busy;
        if (busy) {
            window.addEventListener('beforeunload', onBeforeUnloadBusy);
        } else {
            window.removeEventListener('beforeunload', onBeforeUnloadBusy);
        }
    }

    /**
     * Walk the message list and ensure every assistant tool_use has a matching
     * tool_result in the immediately following user message — the API rejects
     * any orphaned tool_use with HTTP 400.  Repair strategy:
     *  - Trailing assistant with unresolved tool_use → strip it.
     *  - Mid-history assistant with unresolved tool_use → prepend synthetic
     *    tool_result blocks to the next user message (or insert one).
     */
    function sanitizeMessages(msgs) {
        var fixed = [];
        for (var i = 0; i < msgs.length; i++) {
            var msg = msgs[i];
            fixed.push(msg);

            if (msg.role !== 'assistant' || !Array.isArray(msg.content)) { continue; }

            var toolUses = [];
            msg.content.forEach(function (block) {
                if (block.type === 'tool_use' && block.id) {
                    toolUses.push({ id: block.id, name: block.name || '' });
                }
            });
            if (!toolUses.length) { continue; }

            // Trailing assistant with tool_use → no chance for a tool_result to follow.
            if (i === msgs.length - 1) {
                fixed.pop();
                continue;
            }

            var next       = msgs[i + 1];
            var resolved   = [];
            var nextIsUserArr = next && next.role === 'user' && Array.isArray(next.content);
            if (nextIsUserArr) {
                next.content.forEach(function (block) {
                    if (block.type === 'tool_result' && block.tool_use_id) {
                        resolved.push(block.tool_use_id);
                    }
                });
            }

            var missing = toolUses.filter(function (toolUse) { return resolved.indexOf(toolUse.id) === -1; });
            if (!missing.length) { continue; }

            var synthetic = missing.map(function (toolUse) {
                return {
                    type:        'tool_result',
                    tool_use_id: toolUse.id,
                    name:        toolUse.name,
                    content:     'The user moved on without approving this action.',
                };
            });

            if (nextIsUserArr) {
                next.content = synthetic.concat(next.content);
            } else if (next.role === 'user' && typeof next.content === 'string') {
                next.content = synthetic.concat([{ type: 'text', text: next.content }]);
            } else {
                // Next message is assistant (or unknown) — inject a fresh user
                // message carrying just the synthetic tool_results.
                fixed.push({ role: 'user', content: synthetic });
            }
        }
        return fixed;
    }

    function loadChat(id, opts) {
        var silent = opts && opts.silent;
        post('haydi_load_chat', { id: id }, function (res) {
            if (!res.success) {
                if (silent) {
                    // Stale ?chat=<id> in the URL (deleted or never owned by
                    // this user). Strip it so a refresh starts fresh.
                    syncChatIdToUrl(null);
                } else {
                    alert('Failed to load chat.');
                }
                return;
            }

            state.messages   = sanitizeMessages(res.data.messages);
            state.displayLog = Array.isArray(res.data.display) ? res.data.display.slice() : [];
            hideAllProposals();
            currentChatId = id;
            syncChatIdToUrl(currentChatId);
            setChatTitle(res.data.title || null, res.data.title_source || 'fallback');
            sessionApplyCount = res.data.apply_count || 0;
            $('#wpc-metric-session-applies').text(sessionApplyCount);
            if (res.data.usage) {
                sessionUsage = {
                    prompt:      Number(res.data.usage.prompt)      || 0,
                    completion:  Number(res.data.usage.completion)  || 0,
                    total:       Number(res.data.usage.total)       || 0,
                    prompt_peak: Number(res.data.usage.prompt_peak) || 0,
                    prompt_last: Number(res.data.usage.prompt_last) || 0,
                    model:       res.data.usage.model || '',
                };
                updateUsageBadge();
                updateContextBadge();
            } else {
                resetSessionUsage();
            }
            reRenderChat();
            syncSessionActions();

            // Refresh active state in the sidebar history list.
            $('#wpc-sidebar-history-list .wpc-history-item').removeClass('wpc-history-item--active');
            $('#wpc-sidebar-history-list .wpc-history-item[data-id="' + id + '"]').addClass('wpc-history-item--active');
        });
    }

    function reRenderChat() {
        var $msgs = $('#wpc-chat-messages');
        $msgs.empty();

        // Prefer the verbatim display log when one is present (new format).
        if (state.displayLog && state.displayLog.length) {
            state.displayLog.forEach(function (entry) {
                if (entry.role === 'usage') {
                    renderUsageCaption(entry.text);
                } else if (entry.role === 'activity') {
                    renderToolActivity(entry.text);
                } else if (entry.role === 'compact-summary') {
                    renderCompactSummary(entry.text);
                } else {
                    renderMessage(entry.role, entry.text, undefined, entry.t);
                }
            });

            // If the last meaningful entry is a user message, the previous
            // request was interrupted mid-flight (e.g. page navigation).
            var lastMeaningful = null;
            for (var i = state.displayLog.length - 1; i >= 0; i--) {
                var r = state.displayLog[i].role;
                if (r === 'user' || r === 'assistant' || r === 'error') {
                    lastMeaningful = r;
                    break;
                }
            }
            if (lastMeaningful === 'user') {
                renderMessage('error',
                    'The previous request was interrupted. '
                    + '<button type="button" class="wpc-btn-retry">Retry</button>',
                    'wpc-interrupted');
            }
            return;
        }

        // Legacy fallback: derive what we can from the API message history.
        // appendMessage() rebuilds displayLog so the next save captures it.
        state.messages.forEach(function (msg) {
            if (msg.role === 'user') {
                var content = typeof msg.content === 'string' ? msg.content : '';
                if (content) { appendMessage('user', content); }
            } else if (msg.role === 'assistant') {
                var text = '';
                if (typeof msg.content === 'string') {
                    text = msg.content;
                } else if (Array.isArray(msg.content)) {
                    msg.content.forEach(function (block) {
                        if (block.type === 'text' && block.text) { text += block.text; }
                    });
                }
                if (text) { appendMessage('assistant', text); }
            }
        });
    }

    function renderChatList() {
        var $list = $('#wpc-sidebar-history-list');
        $list.html('<p class="wpc-muted wpc-sidebar-empty">' + spinner() + ' Loading\u2026</p>');

        post('haydi_list_chats', {}, function (res) {
            if (!res.success) {
                $list.html('<p class="wpc-muted wpc-sidebar-empty">Failed to load.</p>');
                return;
            }

            var chats = res.data.chats || [];
            var cap    = res.data.cap || 100;
            if (!chats.length) {
                $list.html('<p class="wpc-muted wpc-sidebar-empty">No saved chats yet.</p>');
                return;
            }

            var html = '';
            chats.forEach(function (c) {
                var active = c.id === currentChatId ? ' wpc-history-item--active' : '';
                html +=
                    '<div class="wpc-history-item' + active + '" data-id="' + esc(c.id) + '" role="button" tabindex="0">'
                    + '<div class="wpc-history-item__text">'
                    + '<span class="wpc-history-item__title">' + esc(c.title) + '</span>'
                    + '<span class="wpc-history-item__date">' + esc(timeAgo(c.updated_at)) + '</span>'
                    + '</div>'
                    + '<button class="wpc-history-delete wpc-icon-btn" data-id="' + esc(c.id) + '" title="Delete chat">'
                    + '<span class="dashicons dashicons-trash"></span>'
                    + '</button>'
                    + '</div>';
            });
            if (chats.length >= cap - 10) {
                var remaining = cap - chats.length;
                html += '<p class="wpc-muted wpc-sidebar-cap-warning">'
                    + (remaining <= 0
                        ? 'Chat limit reached. Starting a new chat will remove the oldest.'
                        : remaining + ' chat slot' + (remaining === 1 ? '' : 's') + ' remaining — oldest will be auto-removed when full.')
                    + '</p>';
            }
            $list.html(html);
        });
    }

    // Render history into the sidebar on load and after saves.
    renderChatList();
    syncSessionActions();

    // -------------------------------------------------------------------------
    // Audit log sidebar
    // -------------------------------------------------------------------------

    var AUDIT_ACTION_LABELS = {
        read_file:          'Read',
        list_files:         'List',
        write_applied:      'Write',
        edit_applied:       'Edit',
        file_deleted:       'Delete',
        file_moved:         'Move',
        file_copied:        'Copy',
        dir_deleted:        'Delete dir',
        query_executed:     'Query',
        php_executed:       'PHP',
        plugin_installed:   'Install',
        plugin_activated:   'Activate',
        plugin_deactivated: 'Deactivate',
        fetch_url:          'Fetch',
        log_cleared:        'Cleared',
    };

    function auditActionLabel(action) {
        return AUDIT_ACTION_LABELS[action] || action.replace(/_/g, ' ');
    }

    function renderAuditLog() {
        var $list = $('#wpc-sidebar-audit-list');
        $list.html('<p class="wpc-muted wpc-sidebar-empty">' + spinner() + ' Loading…</p>');

        post('haydi_get_audit_log', {}, function (res) {
            if (!res.success) {
                $list.html('<p class="wpc-muted wpc-sidebar-empty">Failed to load.</p>');
                return;
            }

            var entries = res.data.entries || [];
            if (!entries.length) {
                $list.html('<p class="wpc-muted wpc-sidebar-empty">No activity yet.</p>');
                return;
            }

            var html = '';
            entries.forEach(function (e) {
                var ts      = Math.floor(new Date(e.time.replace(' ', 'T') + 'Z').getTime() / 1000);
                var ago     = timeAgo(ts);
                var label   = auditActionLabel(e.action);
                var path    = e.path ? e.path.replace(/^.*\/wp-content\//, '…/wp-content/') : '';
                html +=
                    '<div class="wpc-audit-entry">'
                    + '<span class="wpc-audit-entry__action">' + esc(label) + '</span>'
                    + '<span class="wpc-audit-entry__meta">'
                    + (path ? '<span class="wpc-audit-entry__path" title="' + esc(e.path) + '">' + esc(path) + '</span>' : '')
                    + '<span class="wpc-audit-entry__time">' + esc(ago) + '</span>'
                    + '</span>'
                    + '</div>';
            });

            $list.html(html);

            var viewAllUrl = (typeof haydi !== 'undefined' && haydi.auditLogUrl) ? haydi.auditLogUrl : '';
            $list.next('.wpc-audit-footer').remove();
            if (viewAllUrl) {
                $list.after('<div class="wpc-audit-footer"><a href="' + esc(viewAllUrl) + '">View full audit log →</a></div>');
            }
        });
    }

    renderAuditLog();

    $(document).on('click', '.wpc-history-item', function (e) {
        if ($(e.target).closest('.wpc-history-delete').length) { return; }
        loadChat($(this).data('id'));
    });

    $(document).on('keydown', '.wpc-history-item', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') { return; }
        if ($(e.target).closest('.wpc-history-delete').length) { return; }
        e.preventDefault();
        loadChat($(this).data('id'));
    });

    $(document).on('click', '.wpc-history-delete', function (e) {
        e.stopPropagation();
        if (!confirm('Delete this chat?')) { return; }
        var id    = $(this).data('id');
        var $item = $(this).closest('.wpc-history-item');
        $item.css('opacity', '0.4');
        post('haydi_delete_chat', { id: id }, function (res) {
            if (res.success) {
                if (currentChatId === id) {
                    currentChatId = null;
                    resetChatTitle();
                }
                renderChatList();
            } else {
                $item.css('opacity', '1');
                alert('Failed to delete chat.');
            }
        });
    });

    // -------------------------------------------------------------------------
    // Utility helpers
    // -------------------------------------------------------------------------

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function post(action, data, callback) {
        data.action = action;
        data.nonce  = haydi.nonce;
        return $.post(haydi.ajaxUrl, data, callback);
    }

    function ajaxResponseMessage(jqXHR) {
        var data;
        var text;

        if (!jqXHR) { return ''; }

        data = jqXHR.responseJSON && jqXHR.responseJSON.data;
        if (data && data.message) {
            return String(data.message);
        }

        text = jqXHR.responseText;
        if (!text) { return ''; }

        text = String(text)
            .replace(/<script[\s\S]*?<\/script>/gi, ' ')
            .replace(/<style[\s\S]*?<\/style>/gi, ' ')
            .replace(/<[^>]+>/g, ' ')
            .replace(/&nbsp;/g, ' ')
            .replace(/&#039;/g, '\'')
            .replace(/&quot;/g, '"')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&amp;/g, '&')
            .replace(/\s+/g, ' ')
            .trim();

        if (text.length > 240) {
            text = text.substring(0, 237) + '...';
        }

        return text;
    }

    function ajaxFailureMessage(jqXHR, fallback) {
        var details = [];
        var responseMessage;

        if (jqXHR && jqXHR.status) {
            details.push('HTTP ' + jqXHR.status);
        }

        responseMessage = ajaxResponseMessage(jqXHR);
        if (responseMessage) {
            details.push(responseMessage);
        }

        if (!details.length) {
            return fallback;
        }

        return fallback + ' (' + details.join(': ') + ')';
    }

    function requestBodyForAction(action, data) {
        var payload = $.extend({}, data || {}, {
            action: action,
            nonce:  haydi.nonce,
        });
        var body = new window.URLSearchParams();
        Object.keys(payload).forEach(function (key) {
            if (payload[key] === undefined || payload[key] === null) { return; }
            body.append(key, payload[key]);
        });
        return body.toString();
    }

    function parseSseEvent(raw) {
        var event = 'message';
        var dataLines = [];
        raw.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) {
                event = $.trim(line.slice(6));
            } else if (line.indexOf('data:') === 0) {
                dataLines.push(line.slice(5).replace(/^ /, ''));
            }
        });

        if (!dataLines.length) { return null; }

        var data = dataLines.join('\n');
        try {
            data = JSON.parse(data);
        } catch (_e) {
            data = { text: data };
        }
        return { event: event, data: data };
    }

    function streamPost(action, data, onEvent) {
        var controller = new window.AbortController();
        var promise = window.fetch(haydi.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body:        requestBodyForAction(action, data),
            signal:      controller.signal,
        }).then(function (response) {
            var contentType = response.headers.get('content-type') || '';
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            if (!response.body || contentType.indexOf('text/event-stream') === -1) {
                throw new Error('Streaming response was not available.');
            }

            var reader = response.body.getReader();
            var decoder = new window.TextDecoder();
            var buffer = '';

            function processBufferedEvents() {
                buffer = buffer.replace(/\r\n/g, '\n');
                var boundary = buffer.indexOf('\n\n');
                while (boundary !== -1) {
                    var raw = buffer.slice(0, boundary);
                    buffer = buffer.slice(boundary + 2);
                    var parsed = parseSseEvent(raw);
                    if (parsed) {
                        onEvent(parsed.event, parsed.data || {});
                    }
                    boundary = buffer.indexOf('\n\n');
                }
            }

            function read() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        if ($.trim(buffer) !== '') {
                            var parsed = parseSseEvent(buffer);
                            if (parsed) {
                                onEvent(parsed.event, parsed.data || {});
                            }
                        }
                        return;
                    }

                    buffer += decoder.decode(chunk.value, { stream: true });
                    processBufferedEvents();
                    return read();
                });
            }

            return read();
        });

        return {
            abort: function () {
                controller.abort();
            },
            promise: promise,
        };
    }

    function humanizeIdentifier(str) {
        return String(str || '')
            .replace(/[-_]+/g, ' ')
            .replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
    }

    function getModelChoiceGroups() {
        var choices = haydi.modelChoices || {};
        var groups = [];
        Object.keys(choices).forEach(function (providerId) {
            var provider = choices[providerId] || {};
            var models = Array.isArray(provider.models) ? provider.models : [];
            if (!models.length) { return; }
            groups.push({
                id:     provider.id || providerId,
                name:   provider.name || humanizeIdentifier(providerId),
                models: models,
            });
        });

        if (groups.length) { return groups; }

        var limits = haydi.modelLimits || {};
        Object.keys(limits).forEach(function (providerId) {
            var provider = limits[providerId] || {};
            var models = provider.models || {};
            var modelIds = Object.keys(models);
            if (!modelIds.length) { return; }
            groups.push({
                id:     provider.provider || providerId,
                name:   provider.name || humanizeIdentifier(provider.provider || providerId),
                models: modelIds.map(function (modelId) {
                    return {
                        id:   modelId,
                        name: (models[modelId] && models[modelId].name) || modelId,
                    };
                }),
            });
        });

        return groups;
    }

    function findModelChoice(providerId, modelId) {
        var groups = getModelChoiceGroups();
        for (var i = 0; i < groups.length; i++) {
            if (groups[i].id !== providerId) { continue; }
            for (var j = 0; j < groups[i].models.length; j++) {
                if (groups[i].models[j].id === modelId) {
                    return {
                        provider:      providerId,
                        providerLabel: groups[i].name,
                        model:         modelId,
                        label:         groups[i].models[j].name || modelId,
                    };
                }
            }
        }
        return null;
    }

    function defaultModelChoice() {
        var groups = getModelChoiceGroups();
        if (!groups.length || !groups[0].models.length) { return null; }
        return findModelChoice(groups[0].id, groups[0].models[0].id);
    }

    function saveSelectedModelPreference() {
        try {
            if (selectedModelPreference) {
                window.localStorage.setItem(
                    MODEL_PREFERENCE_STORAGE_KEY,
                    JSON.stringify({
                        provider: selectedModelPreference.provider,
                        model:    selectedModelPreference.model,
                    })
                );
            } else {
                window.localStorage.removeItem(MODEL_PREFERENCE_STORAGE_KEY);
            }
        } catch (_e) {
            // localStorage can be unavailable in hardened browser contexts.
        }
    }

    function restoreSelectedModelPreference() {
        try {
            var raw = window.localStorage.getItem(MODEL_PREFERENCE_STORAGE_KEY);
            if (raw) {
                var saved = JSON.parse(raw);
                selectedModelPreference = findModelChoice(saved.provider, saved.model);
            }
        } catch (_e) {
            selectedModelPreference = null;
        }
        if (!selectedModelPreference) {
            selectedModelPreference = defaultModelChoice();
            saveSelectedModelPreference();
        }
    }

    function modelPreferencePayload() {
        if (!selectedModelPreference) { return {}; }
        return {
            model_provider: selectedModelPreference.provider,
            model:          selectedModelPreference.model,
        };
    }

    function renderModelMenu() {
        restoreSelectedModelPreference();

        var groups = getModelChoiceGroups();
        var selectedKey = selectedModelPreference
            ? selectedModelPreference.provider + '::' + selectedModelPreference.model
            : '';
        var html = '';

        groups.forEach(function (group) {
            html += '<div class="wpc-model-menu__group">'
                + '<div class="wpc-model-menu__provider">' + esc(group.name) + '</div>';
            group.models.forEach(function (model) {
                var key = group.id + '::' + model.id;
                var selected = key === selectedKey;
                html += '<button type="button" class="wpc-model-menu__item'
                    + (selected ? ' is-selected' : '')
                    + '" role="menuitemradio" aria-checked="' + (selected ? 'true' : 'false') + '"'
                    + ' data-provider="' + esc(group.id) + '" data-model="' + esc(model.id) + '">'
                    + '<span class="wpc-model-menu__model-label">' + esc(model.name || model.id) + '</span>'
                    + '<span class="wpc-model-menu__check">'
                    + (selected ? '<span class="dashicons dashicons-yes"></span>' : '')
                    + '</span></button>';
            });
            html += '</div>';
        });

        if (!groups.length) {
            html += '<div class="wpc-model-menu__provider">No configured models</div>';
        }

        $('#wpc-model-menu').html(html);
        $('#wpc-current-model-label').text(selectedModelPreference ? selectedModelPreference.label : 'No models');
        $('#wpc-btn-model-menu').prop('disabled', !groups.length);
    }

    function closeModelMenu() {
        $('#wpc-model-menu').addClass('wpc-hidden');
        $('#wpc-btn-model-menu').attr('aria-expanded', 'false');
    }

    function toggleModelMenu() {
        var isHidden = $('#wpc-model-menu').hasClass('wpc-hidden');
        $('#wpc-model-menu').toggleClass('wpc-hidden', !isHidden);
        $('#wpc-btn-model-menu').attr('aria-expanded', isHidden ? 'true' : 'false');
    }

    /**
     * Wrap an approval-AJAX jqXHR with applyInFlight tracking.  While the
     * request is in flight sendMessage() short-circuits, preventing a race
     * where the user types a new message and the success callback later
     * pushes a competing tool_result for the same tool_use_id.
     */
    function trackApply(jqxhr) {
        state.applyInFlight = true;
        jqxhr.always(function () { state.applyInFlight = false; });
        return jqxhr;
    }

    function spinner() {
        return '<span class="wpc-spinner"></span>';
    }

    // -------------------------------------------------------------------------
    // Suggestion chips — one prompt per capability category, surfaced beneath
    // the greeting on a fresh chat. Hidden once the user actually
    // sends a message or loads an existing chat.
    // -------------------------------------------------------------------------

    /**
     * Pure: pick one random entry per category from a pool object.
     *
     * `pool` keys are category labels; values are arrays of prompt strings.
     * Returns one string per non-empty category, in Object.keys order. The
     * `rng` arg is injectable so tests can drive a deterministic sequence.
     */
    function pickSuggestionChips(pool, rng) {
        rng = rng || Math.random;
        var picks = [];
        Object.keys(pool || {}).forEach(function (key) {
            var bucket = pool[key];
            if (!Array.isArray(bucket) || bucket.length === 0) { return; }
            var idx = Math.floor(rng() * bucket.length) % bucket.length;
            picks.push(bucket[idx]);
        });
        return picks;
    }

    function renderSuggestionChips() {
        $('#wpc-chat-suggestions').remove();

        var pool  = haydi.suggestions || {};
        var chips = pickSuggestionChips(pool);
        if (!chips.length) { return; }

        var hint = haydi.suggestionHint || 'Try one of these:';
        var html = '<div id="wpc-chat-suggestions" class="wpc-suggestions">'
            + '<div class="wpc-suggestions__hint">' + esc(hint) + '</div>'
            + '<div class="wpc-suggestions__chips">';
        chips.forEach(function (text) {
            html += '<button type="button" class="wpc-suggestion-chip">'
                + esc(text) + '</button>';
        });
        html += '</div></div>';

        $('#wpc-chat-messages').append(html);
    }

    function hideSuggestionChips() {
        $('#wpc-chat-suggestions').remove();
    }

    // Click → drop the prompt into the input, focus it, and clear the chips.
    $('#wpc-chat-messages').on('click', '.wpc-suggestion-chip', function () {
        var text = $(this).text();
        $('#wpc-chat-input').val(text).trigger('focus');
        hideSuggestionChips();
    });

    $('#wpc-chat-messages').on('click', '.wpc-btn-retry', function () {
        $(this).closest('.wpc-message').remove();
        runChatRequest();
    });

    // -------------------------------------------------------------------------
    // Proposal panel visibility — the panel is rendered in PHP near the
    // workspace start, then docked immediately before the composer wrapper so
    // approvals stay near the text box instead of appearing at the top.
    // -------------------------------------------------------------------------

    function dockProposalPanel() {
        var $panel = $('#wpc-editor-panel');
        var $wrap  = $('.wpc-chat-input-wrap');
        if ($panel.length && $wrap.length && !$panel.next().is($wrap)) {
            $wrap.before($panel);
        }
    }

    dockProposalPanel();

    function syncEditorPanelVisibility() {
        var anyVisible = $('#wpc-editor-panel [id$="-section"]').filter(function () {
            return !$(this).hasClass('wpc-hidden');
        }).length > 0;
        $('#wpc-editor-panel').toggleClass('wpc-hidden', !anyVisible);
    }

    // -------------------------------------------------------------------------
    // Chat
    // -------------------------------------------------------------------------

    $('#wpc-btn-send').on('click', sendMessage);
    renderModelMenu();

    $('#wpc-btn-model-menu').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleModelMenu();
    });

    $('#wpc-model-menu').on('click', '.wpc-model-menu__item', function () {
        var providerId = $(this).data('provider') || '';
        var modelId = $(this).data('model') || '';
        selectedModelPreference = findModelChoice(providerId, modelId) || defaultModelChoice();
        saveSelectedModelPreference();
        renderModelMenu();
        closeModelMenu();
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.wpc-model-menu').length) {
            closeModelMenu();
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeModelMenu();
        }
    });

    function syncSessionActions() {
        $('#wpc-btn-compact').prop('disabled', !state.messages.length);
        $('#wpc-btn-download').prop('disabled', !state.displayLog.length);
    }

    $('#wpc-btn-compact').on('click', function () {
        compactChat();
    });

    $('#wpc-btn-download').on('click', function () {
        downloadChat();
    });

    $('#wpc-chat-input').on('keydown', function (e) {
        // Enter sends; Shift+Enter inserts a newline.
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    $('#wpc-btn-new-chat').on('click', function () {
        if (!confirm('Start a new chat? The current chat will be saved and a new one will begin.')) {
            return;
        }
        // Save synchronously: a debounced save firing after currentChatId
        // is cleared below would create a stray new chat.
        if (saveTimer) { window.clearTimeout(saveTimer); saveTimer = null; }
        saveCurrentChatNow({ detached: true });
        currentChatId   = null;
        syncChatIdToUrl(null);
        state.messages   = [];
        state.displayLog = [];
        attachments      = [];
        resetChatTitle();
        renderAttachments();
        hideAllProposals();
        resetApplyCounter();
        resetSessionUsage();
        syncSessionActions();
        $('#wpc-sidebar-history-list .wpc-history-item').removeClass('wpc-history-item--active');
        $('#wpc-chat-messages').html(
            '<div class="wpc-message wpc-message--assistant">'
            + 'New chat started. How can I help?'
            + '</div>'
        );
        renderSuggestionChips();
    });

    function compactChat(opts, done) {
        opts = opts || {};
        if (state.applyInFlight || state.busy || !state.messages.length) {
            if (done) { done(false); }
            return;
        }
        if (state.pending) {
            appendMessage('error', 'Resolve or cancel the pending proposal before compacting the chat.');
            if (done) { done(false); }
            return;
        }
        if (!opts.auto && !confirm('Compact this chat? Older history will be permanently replaced with a summary — this cannot be undone.')) {
            if (done) { done(false); }
            return;
        }

        setBusy(true);
        $('#wpc-btn-send').prop('disabled', true);
        $('#wpc-btn-compact').prop('disabled', true);
        var thinkingText = opts.auto
            ? spinner() + ' Compacting chat before continuing…'
            : spinner() + ' Compacting chat…';
        var $thinking = appendMessage('assistant', thinkingText, 'wpc-thinking');

        state.xhr = post('haydi_compact_chat', $.extend({
            messages: JSON.stringify(sanitizeMessages(state.messages)),
        }, modelPreferencePayload()), function (res) {
            $thinking.remove();
            setBusy(false);
            state.xhr = null;
            $('#wpc-btn-send').prop('disabled', false);
            $('#wpc-btn-compact').prop('disabled', false);

            if (!res.success) {
                appendMessage('error', 'Error: ' + (res.data.message || 'Compaction failed.'));
                saveCurrentChat();
                if (done) { done(false); }
                return;
            }

            state.messages = sanitizeMessages(res.data.messages || []);
            state.displayLog = [];
            $('#wpc-chat-messages').empty();
            appendMessage(
                'assistant',
                opts.auto
                    ? 'Chat compacted. I kept the important context and will continue with your message.'
                    : 'Chat compacted. I kept a summary and cleared the older transcript.'
            );

            if (res.data.summary) {
                var t = Date.now();
                renderCompactSummary(res.data.summary);
                state.displayLog.push({ role: 'compact-summary', text: res.data.summary, t: t });
            }

            if (res.data.usage) {
                recordUsage(res.data.usage);
            }

            sessionUsage.prompt_peak = 0;
            sessionUsage.prompt_last = 0;
            updateContextBadge();
            saveCurrentChat();
            if (done) { done(true); }
        }).fail(function (jqXHR) {
            $thinking.remove();
            setBusy(false);
            state.xhr = null;
            $('#wpc-btn-send').prop('disabled', false);
            $('#wpc-btn-compact').prop('disabled', false);
            if (jqXHR.statusText !== 'abort') {
                appendMessage('error', 'Compaction request failed. Check your network connection or the WordPress error log.');
                saveCurrentChat();
            }
            if (done) { done(false); }
        });
    }

    function formatDisplayLogAsText(displayLog) {
        var roleLabels = { user: 'You', assistant: 'Assistant', 'compact-summary': 'Summary' };
        var lines = [];
        displayLog.forEach(function (entry) {
            if (entry.role !== 'user' && entry.role !== 'assistant' && entry.role !== 'compact-summary') { return; }
            var label = roleLabels[entry.role];
            var timestamp = entry.t ? new Date(entry.t).toLocaleString() : '';
            lines.push((timestamp ? '[' + timestamp + '] ' : '') + label + ':');
            lines.push(entry.text);
            lines.push('');
        });
        return lines;
    }

    function downloadChat() {
        if (!state.displayLog.length) { return; }

        var lines = formatDisplayLogAsText(state.displayLog);

        var blob = new Blob([lines.join('\n')], { type: 'text/plain' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'chat.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function appendUserMessageToHistory(apiText) {
        // If a proposal is outstanding, the previous assistant turn ended in a
        // tool_use that has no tool_result yet. Sending a bare text message
        // would leave that tool_use unmatched and the API rejects it (HTTP 400).
        // Bundle a cancel tool_result for the pending action with the user's
        // new text into a single user message so every tool_use is resolved.
        if (state.pending) {
            var p = state.pending;
            state.messages.push({
                role:    'user',
                content: (p.pre_results || []).concat([
                    {
                        type:        'tool_result',
                        tool_use_id: p.tool_use_id,
                        name:        p.tool_name,
                        content:     'The user did not approve the proposed ' + PROPOSALS[p.kind].label + ' and sent a new instruction instead.',
                    },
                    { type: 'text', text: apiText },
                ]),
            });
            hideAllProposals();
            return true;
        }

        state.messages.push({ role: 'user', content: apiText });
        return false;
    }

    function sendMessage() {
        // An approval AJAX (Apply/Execute/etc.) is mid-flight.  Don't let a
        // new chat send race against its still-pending tool_result push.
        if (state.applyInFlight) { return; }

        if (state.busy) {
            if (state.xhr) {
                state.xhr.abort();
                state.xhr = null;
            }
            return;
        }

        var text = $.trim($('#wpc-chat-input').val());
        if (text === '' && !attachments.length) { return; }

        // Build the API content: attachment blocks first, then the user's text.
        var apiText = '';
        if (attachments.length) {
            apiText = attachments.map(function (att) {
                var ext = att.name.split('.').pop();
                return '**Attached: ' + att.name + '**\n```' + ext + '\n' + att.content + '\n```';
            }).join('\n\n');
            if (text) { apiText += '\n\n' + text; }
        } else {
            apiText = text;
        }

        // Display: show the user's text and attachment filenames (not full content).
        var displayText = attachments.map(function (att) {
            return '📎 ' + att.name;
        }).join('  ');
        if (text) { displayText = displayText ? displayText + '\n\n' + text : text; }

        attachments = [];
        renderAttachments();

        $('#wpc-chat-input').val('');
        hideSuggestionChips();

        if (shouldAutoCompactBeforeSend()) {
            compactChat({ auto: true }, function (ok) {
                if (!ok) {
                    $('#wpc-chat-input').val(displayText).trigger('focus');
                    return;
                }

                appendMessage('user', displayText);
                appendUserMessageToHistory(apiText);
                state.messages = sanitizeMessages(state.messages);
                saveCurrentChat();
                runChatRequest();
            });
            return;
        }

        appendMessage('user', displayText);
        appendUserMessageToHistory(apiText);
        // Defensive: repair any earlier orphaned tool_use blocks that may have
        // been saved by an older version of this script before resending.
        state.messages = sanitizeMessages(state.messages);
        syncSessionActions();
        saveCurrentChat();

        runChatRequest();
    }

    function supportsChatStreaming() {
        return !!(window.fetch && window.URLSearchParams && window.AbortController && window.ReadableStream && window.TextDecoder);
    }

    function finishChatRequest() {
        setBusy(false);
        state.xhr = null;
        $('#wpc-btn-send').html(SEND_ICON_SVG).removeClass('wpc-btn-stop');
    }

    function handleChatResponseData(data) {
        // Update full message history (includes tool turns). sanitizeMessages
        // strips trailing assistant tool_use blocks on load, so saving while
        // a pending approval is outstanding is safe.
        if (data.messages) {
            state.messages = data.messages;
        }

        // Show read-only tool activity before the assistant's prose. This
        // is built from actual server-side tool execution metadata, not
        // from the model's narration.
        if (data.activity && data.activity.length) {
            appendToolActivity(data.activity);
        }

        // Show the AI's text response.
        if (data.text && $.trim(data.text) !== '') {
            appendMessage('assistant', data.text);
        }

        // Record token usage for this turn — appends a per-turn caption
        // and updates the running session badge.
        if (data.usage) {
            recordUsage(data.usage);
        }

        // Persist as soon as the response is rendered, so a refresh
        // (or browser crash) cannot lose the latest assistant turn.
        // Saving here — after appendMessage — guarantees the displayLog
        // snapshot includes the freshly-rendered text.
        saveCurrentChat();

        // Surface whatever proposal the server returned (at most one).
        Object.keys(PROPOSALS).some(function (kind) {
            var cfg = PROPOSALS[kind];
            if (!data[cfg.responseKey]) return false;
            state.pending = $.extend({ kind: kind }, data[cfg.responseKey]);
            cfg.show(state.pending);
            return true;
        });

        if (state.autoAccept && state.pending) {
            $('#' + PROPOSALS[state.pending.kind].confirmBtnId).click();
        }
    }

    function httpErrorMessage(jqXHR) {
        var status = jqXHR ? jqXHR.status : 0;
        if (!status || status === 0)  { return 'Lost connection to the server.'; }
        if (status === 403)           { return 'Access denied (403) — your session may have expired. Try reloading the page.'; }
        if (status === 408 || status === 504) { return 'The request timed out (' + status + '). The server may be busy — try again.'; }
        if (status === 500)           { return 'The server returned an error (500). Check the WordPress error log for details.'; }
        return 'Request failed (HTTP ' + status + ').';
    }

    function appendChatError(msg, withRetry) {
        var html = esc(msg) + (withRetry
            ? ' <button type="button" class="wpc-btn-retry">Retry</button>'
            : '');
        renderMessage('error', html, 'wpc-chat-error');
        $('#wpc-chat-messages').scrollTop($('#wpc-chat-messages')[0].scrollHeight);
    }

    function runLegacyChatRequest() {
        var $thinking = appendMessage('assistant', spinner() + ' Working…', 'wpc-thinking');

        state.xhr = post('haydi_chat', $.extend({
            messages: JSON.stringify(state.messages),
        }, modelPreferencePayload()), function (res) {
            $thinking.remove();
            finishChatRequest();

            if (!res.success) {
                appendChatError(res.data.message || 'Unknown error.', true);
                saveCurrentChat();
                return;
            }

            handleChatResponseData(res.data);
        }).fail(function (jqXHR) {
            $thinking.remove();
            finishChatRequest();
            if (jqXHR.statusText !== 'abort') {
                appendChatError(httpErrorMessage(jqXHR), true);
                saveCurrentChat();
            }
        });
    }

    function runStreamingChatRequest() {
        var progress = createStreamProgress();
        var $assistantPreview = null;
        var finalData = null;

        state.xhr = streamPost('haydi_chat_stream', $.extend({
            messages: JSON.stringify(state.messages),
        }, modelPreferencePayload()), function (event, data) {
            if (event === 'status') {
                progress.setStatus(data.message || 'Working...');
            } else if (event === 'assistant_text') {
                $assistantPreview = updateStreamAssistantPreview($assistantPreview, data.text || '');
            } else if (event === 'tool_start') {
                progress.addStep(data.label || 'Using tool', data.summary || '', 'ok', 'start');
            } else if (event === 'tool_done') {
                var activity = data.activity || {};
                progress.addStep(
                    activity.label || data.label || 'Finished tool',
                    activity.summary || '',
                    data.status || activity.status || 'ok',
                    'done'
                );
            } else if (event === 'final') {
                finalData = data;
            } else if (event === 'error') {
                throw new Error(data.message || 'Request failed.');
            }
        });

        state.xhr.promise.then(function () {
            progress.remove();
            if ($assistantPreview) { $assistantPreview.remove(); }
            finishChatRequest();

            if (!finalData) {
                appendChatError('Request ended before the assistant returned a response.', true);
                saveCurrentChat();
                return;
            }

            handleChatResponseData(finalData);
        }).catch(function (err) {
            progress.remove();
            if ($assistantPreview) { $assistantPreview.remove(); }
            finishChatRequest();

            if (err && err.name === 'AbortError') { return; }

            var fetchMsg = err && err.message ? err.message : '';
            var friendlyMsg = (fetchMsg === 'Failed to fetch' || fetchMsg === 'NetworkError when attempting to fetch resource.' || !fetchMsg)
                ? 'Lost connection to the server.'
                : fetchMsg;
            appendChatError(friendlyMsg, true);
            saveCurrentChat();
        });
    }

    /**
     * Post the current state.messages to the server and handle the response.
     * The server runs the full agentic loop and returns either:
     *   - A text response (done)
     *   - A pending_write (needs human approval)
     */
    function runChatRequest() {
        setBusy(true);
        $('#wpc-btn-send').html(STOP_ICON_SVG).addClass('wpc-btn-stop');

        if (supportsChatStreaming()) {
            runStreamingChatRequest();
            return;
        }

        runLegacyChatRequest();
    }

    // -------------------------------------------------------------------------
    // Proposed change UI
    // -------------------------------------------------------------------------

    // Compute a line-level diff using LCS. Returns { type: 'equal'|'insert'|'delete', line }.
    function lineDiff(oldLines, newLines) {
        var n = oldLines.length, m = newLines.length;

        if (n === 0) {
            return newLines.map(function (l) { return { type: 'insert', line: l }; });
        }
        if (m === 0) {
            return oldLines.map(function (l) { return { type: 'delete', line: l }; });
        }

        // Safety: avoid building a huge table for very large files.
        if (n * m > 4000000) {
            return oldLines.map(function (l) { return { type: 'delete', line: l }; })
                .concat(newLines.map(function (l) { return { type: 'insert', line: l }; }));
        }

        var dp = new Array(n + 1);
        for (var i = 0; i <= n; i++) {
            dp[i] = new Int32Array(m + 1);
        }
        for (var i = 1; i <= n; i++) {
            for (var j = 1; j <= m; j++) {
                if (oldLines[i - 1] === newLines[j - 1]) {
                    dp[i][j] = dp[i - 1][j - 1] + 1;
                } else {
                    dp[i][j] = dp[i - 1][j] > dp[i][j - 1] ? dp[i - 1][j] : dp[i][j - 1];
                }
            }
        }

        var out = [];
        var i = n, j = m;
        while (i > 0 || j > 0) {
            if (i > 0 && j > 0 && oldLines[i - 1] === newLines[j - 1]) {
                out.push({ type: 'equal',  line: oldLines[i - 1] });
                i--; j--;
            } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
                out.push({ type: 'insert', line: newLines[j - 1] });
                j--;
            } else {
                out.push({ type: 'delete', line: oldLines[i - 1] });
                i--;
            }
        }
        return out.reverse();
    }

    function renderUnifiedDiff(before, after) {
        var oldLines = before.length ? before.split('\n') : [];
        var newLines = after.length  ? after.split('\n')  : [];
        var diff = lineDiff(oldLines, newLines);
        var html = '';
        diff.forEach(function (entry) {
            if (entry.type === 'insert') {
                html += '<span class="wpc-dl wpc-dl-add">+' + esc(entry.line) + '\n</span>';
            } else if (entry.type === 'delete') {
                html += '<span class="wpc-dl wpc-dl-del">-' + esc(entry.line) + '\n</span>';
            } else {
                html += '<span class="wpc-dl wpc-dl-ctx"> ' + esc(entry.line) + '\n</span>';
            }
        });
        return '<div class="wpc-dl-wrap">' + (html || '<span class="wpc-dl wpc-dl-ctx"> (empty file)</span>') + '</div>';
    }

    function isReplaceAll(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function countOccurrences(haystack, needle) {
        if (!needle) { return 0; }
        var count = 0;
        var pos = 0;
        while (true) {
            pos = haystack.indexOf(needle, pos);
            if (pos === -1) { return count; }
            count++;
            pos += needle.length;
        }
    }

    function applyExactEdit(content, oldString, newString, replaceAll) {
        if (!oldString) {
            return { ok: false, error: 'oldString is required.' };
        }

        var count = countOccurrences(content, oldString);
        if (count === 0) {
            return { ok: false, error: 'oldString was not found in the current file content.' };
        }
        if (!replaceAll && count > 1) {
            return { ok: false, error: 'oldString appears more than once.' };
        }

        return {
            ok:      true,
            count:   count,
            content: content.split(oldString).join(newString || ''),
        };
    }

    // -------------------------------------------------------------------------
    // Proposal table — one entry per approvable AI action.
    //
    // setupProposal() at the bottom of this section reads each entry to wire
    // up the show / hide / confirm-button / cancel-button handlers, replacing
    // ten near-identical hand-rolled blocks. Custom hooks (`show`, `hide`,
    // `onSuccess`, `onApplied`) cover the few per-type behaviours that
    // genuinely differ — diff rendering for write, editor cleanup after a
    // delete that targeted the open file, etc.
    // -------------------------------------------------------------------------

    var PROPOSALS = {
        extension: {
            kind:         'extension',
            responseKey:  'pending_extension',
            sectionId:    'wpc-extension-section',
            confirmBtnId: 'wpc-btn-confirm-extension',
            cancelBtnId:  'wpc-btn-cancel-extension',
            statusId:     'wpc-extension-status',
            label:        'action',
            getAjaxAction: function (p) { return p.ajax_action || ''; },
            buildPayload: function (p) {
                var out = {};
                (p.payload_keys || []).forEach(function (k) { out[k] = p[k] !== undefined ? p[k] : ''; });
                return out;
            },
            show: function (p) {
                $('#wpc-extension-label').text(p.label || 'Action');
                $('#wpc-extension-reason').text(p.reason || '(no reason given)');
                $('#wpc-extension-section').removeClass('wpc-hidden');
                $('#wpc-extension-status').text('').removeClass('is-error');
                syncEditorPanelVisibility();
                scrollChatToBottom();

                var $payload = $('#wpc-extension-payload');

                if (p.tool_name === 'write_file' && p.path) {
                    $payload.html('<span class="wpc-dl wpc-dl-ctx"> Loading…\n</span>');
                    post('haydi_read_file', { path: p.path }, function (res) {
                        $payload.html(renderUnifiedDiff(res.success ? res.data.content : '', p.content || ''));
                    });
                } else if (p.tool_name === 'edit' && p.filePath) {
                    $payload.html('<span class="wpc-dl wpc-dl-ctx"> Loading…\n</span>');
                    post('haydi_read_file', { path: p.filePath }, function (res) {
                        if (!res.success) {
                            $payload.html('<span class="wpc-dl wpc-dl-del"> ' + esc(res.data.message || 'Failed to read file.') + '\n</span>');
                            return;
                        }
                        var edit = applyExactEdit(res.data.content, p.oldString || '', p.newString || '', isReplaceAll(p.replaceAll));
                        if (!edit.ok) {
                            $('#wpc-extension-status').text(edit.error).addClass('is-error');
                            $payload.html(renderUnifiedDiff(res.data.content, res.data.content));
                            return;
                        }
                        $payload.html(renderUnifiedDiff(res.data.content, edit.content));
                    });
                } else {
                    var dump = {};
                    (p.payload_keys || []).forEach(function (k) {
                        if (k !== 'reason') { dump[k] = p[k] !== undefined ? p[k] : ''; }
                    });
                    $payload.text(JSON.stringify(dump, null, 2));
                }
            },
            successUi: function (p) {
                // For destructive ops the file is gone — skip the view link.
                var noLink = p.tool_name === 'delete_file' || p.tool_name === 'delete_dir';
                var path   = noLink ? '' : (p.path || p.filePath || p.dest || p.original_path || '');
                var base   = (p.label || 'Action') + ' completed.';
                return path ? base + ' ' + fileViewMarkdownLink(path) + styleVariationRefreshHint(path) : base;
            },
            successAi: function (p, data) {
                var noLink = p.tool_name === 'delete_file' || p.tool_name === 'delete_dir';
                var path   = noLink ? '' : (p.path || p.filePath || p.dest || p.original_path || '');
                var base   = (p.label || 'Action') + ' completed successfully.';
                if (path) { return base + ' ' + fileViewMarkdownLink(path) + styleVariationRefreshHint(path); }
                return base + (data && data.result ? ' ' + data.result : '');
            },
        },

        install: {
            kind:         'install',
            responseKey:  'pending_install',
            sectionId:    'wpc-install-section',
            confirmBtnId: 'wpc-btn-confirm-install',
            cancelBtnId:  'wpc-btn-cancel-install',
            statusId:     'wpc-install-status',
            ajaxAction:   'haydi_install_plugin',
            payloadKeys:  ['slug', 'reason'],
            label:        'plugin installation',
            confirmText:  function (p) { return 'Install plugin "' + p.slug + '" from WordPress.org?\n\nThis will download and install the plugin. Continue?'; },
            // Keep the install card terse: the slug is the target, while the
            // model-supplied reason remains in the payload/logs.
            show: function (p) {
                $('#wpc-install-slug').text(p.slug);
                $('#wpc-install-reason').text(p.reason || '(no reason given)');
                $('#wpc-install-section').removeClass('wpc-hidden');
                $('#wpc-install-status').text('').removeClass('is-error');
                syncEditorPanelVisibility();
                scrollChatToBottom();
            },
            // Plugin file path comes back from the server and is appended to both messages.
            onSuccess: function (p, data) {
                var pluginFile = data.plugin_file || '';
                $('#wpc-install-status').text('Installed.');
                appendMessage('assistant', 'Plugin "' + p.slug + '" installed' + (pluginFile ? ' (' + pluginFile + ')' : '') + '.');
                return 'Plugin "' + p.slug + '" installed successfully.' + (pluginFile ? ' Plugin file: ' + pluginFile : '');
            },
        },

        activate: {
            kind:         'activate',
            responseKey:  'pending_activate',
            sectionId:    'wpc-activate-section',
            confirmBtnId: 'wpc-btn-confirm-activate',
            cancelBtnId:  'wpc-btn-cancel-activate',
            statusId:     'wpc-activate-status',
            ajaxAction:   'haydi_activate_plugin',
            payloadKeys:  ['plugin', 'reason'],
            label:        'plugin activation',
            showFields:   { 'wpc-activate-plugin': 'plugin', 'wpc-activate-reason': 'reason' },
            successUi:    function (p) { return 'Plugin "' + p.plugin + '" activated. Refresh WP Admin to see any new plugin menu/sidebar items.'; },
            successAi:    function (p) { return 'Plugin "' + p.plugin + '" activated successfully. Tell the user to refresh WP Admin if they expect new plugin menu/sidebar items to appear.'; },
        },

        deactivate: {
            kind:         'deactivate',
            responseKey:  'pending_deactivate',
            sectionId:    'wpc-deactivate-section',
            confirmBtnId: 'wpc-btn-confirm-deactivate',
            cancelBtnId:  'wpc-btn-cancel-deactivate',
            statusId:     'wpc-deactivate-status',
            ajaxAction:   'haydi_deactivate_plugin',
            payloadKeys:  ['plugin', 'reason'],
            label:        'plugin deactivation',
            showFields:   { 'wpc-deactivate-plugin': 'plugin', 'wpc-deactivate-reason': 'reason' },
            confirmText:  function (p) { return 'Deactivate plugin "' + p.plugin + '"?\n\nThis may affect site functionality. Continue?'; },
            successUi:    function (p) { return 'Plugin "' + p.plugin + '" deactivated.'; },
            successAi:    function (p) { return 'Plugin "' + p.plugin + '" deactivated successfully.'; },
        },
    };

    // Default show: write each `showFields` entry into a #id with .text(),
    // then unhide the section and clear its status line.
    function defaultShow(cfg) {
        return function (p) {
            Object.keys(cfg.showFields || {}).forEach(function (id) {
                var key      = cfg.showFields[id];
                var fallback = key === 'reason' ? '(no reason given)' : '';
                $('#' + id).text(p[key] || fallback);
            });
            $('#' + cfg.sectionId).removeClass('wpc-hidden');
            $('#' + cfg.statusId).text('').removeClass('is-error');
            syncEditorPanelVisibility();
            scrollChatToBottom();
            var $btn = $('#' + cfg.confirmBtnId);
            if ($btn.length) {
                $btn[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        };
    }

    function defaultHide(cfg) {
        return function () {
            $('#' + cfg.sectionId).addClass('wpc-hidden');
            syncEditorPanelVisibility();
        };
    }

    function buildPayload(cfg, p) {
        var out = {};
        cfg.payloadKeys.forEach(function (k) { out[k] = p[k] !== undefined ? p[k] : ''; });
        return out;
    }

    function fileBaseName(path) {
        path = String(path || '').replace(/\/+$/, '');
        var parts = path.split('/');
        return parts.length ? parts[parts.length - 1] : path;
    }

    function fileViewMarkdownLink(path) {
        return '[' + fileBaseName(path) + '](wpc-view:' + path + ')';
    }

    function isThemeStyleVariationPath(path) {
        return /\/wp-content\/themes\/[^/]+\/styles\/[^/]+\.json$/i.test(String(path || ''));
    }

    function styleVariationRefreshHint(path) {
        return isThemeStyleVariationPath(path)
            ? ' Refresh the Site Editor to see the new style variation.'
            : '';
    }

    var playgroundClientPromise = null;
    var playgroundPreflightMirror = null;

    function isPhpPath(path) {
        return /\.php$/i.test(String(path || ''));
    }

    function dirname(path) {
        path = String(path || '').replace(/\/+$/, '');
        var idx = path.lastIndexOf('/');
        return idx > 0 ? path.substring(0, idx) : '/';
    }

    function getPlaygroundFrame() {
        var iframe = document.getElementById('wpc-playground-preflight-frame');
        if (iframe) { return iframe; }

        iframe = document.createElement('iframe');
        iframe.id = 'wpc-playground-preflight-frame';
        iframe.title = 'WordPress Playground preflight';
        iframe.setAttribute('aria-hidden', 'true');
        iframe.className = 'wpc-playground-preflight';
        document.body.appendChild(iframe);
        return iframe;
    }

    function getPlaygroundClient() {
        if (playgroundClientPromise) { return playgroundClientPromise; }

        playgroundClientPromise = import(haydi.playgroundClient || 'https://playground.wordpress.net/client/index.js')
            .then(function (mod) {
                return mod.startPlaygroundWeb({
                    iframe:    getPlaygroundFrame(),
                    remoteUrl: haydi.playgroundRemote || 'https://playground.wordpress.net/remote.html',
                    blueprint: {
                        preferredVersions: {
                            php: 'latest',
                            wp:  'latest',
                        },
                        steps: [
                            { step: 'login', username: 'admin', password: 'password' },
                        ],
                    },
                });
            })
            .then(function (client) {
                return client.isReady().then(function () { return client; });
            })
            .catch(function (err) {
                playgroundClientPromise = null;
                throw err;
            });

        return playgroundClientPromise;
    }

    function warmPlaygroundClient() {
        if (!isPlaygroundPreflightEnabled()) { return; }
        getPlaygroundClient().catch(function () {});
    }

    function playgroundContextKey(payload) {
        return [
            payload.type || '',
            payload.root || '',
            payload.wp_root || '',
            payload.boot || '',
            payload.entry_file || '',
        ].join('\n');
    }

    function getPlaygroundMirror(payload) {
        var contextKey = playgroundContextKey(payload);
        if (!playgroundPreflightMirror || playgroundPreflightMirror.contextKey !== contextKey) {
            playgroundPreflightMirror = {
                contextKey: contextKey,
                files:      {},
            };
        }
        return playgroundPreflightMirror;
    }

    function resetPlaygroundMirror() {
        playgroundPreflightMirror = null;
    }

    function getProposedWritePath(cfg, p) {
        if (cfg.kind === 'write') { return p.path || ''; }
        if (cfg.kind === 'edit') { return p.filePath || ''; }
        return '';
    }

    function getProposedWriteContent(cfg, p) {
        if (cfg.kind === 'write') {
            return Promise.resolve(p.content || '');
        }

        if (cfg.kind !== 'edit') {
            return Promise.resolve(null);
        }

        return new Promise(function (resolve, reject) {
            post('haydi_read_file', { path: p.filePath }, function (res) {
                if (!res.success) {
                    reject(new Error((res.data && res.data.message) || 'Failed to read file before Playground preflight.'));
                    return;
                }

                var edit = applyExactEdit(res.data.content, p.oldString || '', p.newString || '', isReplaceAll(p.replaceAll));
                if (!edit.ok) {
                    reject(new Error(edit.error));
                    return;
                }
                resolve(edit.content);
            }).fail(function (jqXHR) {
                reject(new Error(ajaxFailureMessage(jqXHR, 'Failed to read file before Playground preflight.')));
            });
        });
    }

    function preparePlaygroundPayload(path) {
        return new Promise(function (resolve, reject) {
            post('haydi_prepare_playground_preflight', { path: path }, function (res) {
                if (!res.success) {
                    reject(new Error((res.data && res.data.message) || 'Could not prepare Playground preflight.'));
                    return;
                }
                resolve(res.data);
            }).fail(function (jqXHR) {
                reject(new Error(ajaxFailureMessage(jqXHR, 'Could not prepare Playground preflight.')));
            });
        });
    }

    function phpString(value) {
        return JSON.stringify(String(value || ''));
    }

    function playgroundResponseText(response) {
        if (!response) { return ''; }
        if (typeof response.text === 'string') { return response.text; }
        if (response.body) { return String(response.body); }
        return '';
    }

    function assertPlaygroundResponse(response, label) {
        var text = playgroundResponseText(response);
        if (/Fatal error|Parse error|Uncaught Error|There has been a critical error/i.test(text)) {
            throw new Error(label + ' returned a PHP error.');
        }
        return text;
    }

    function writePlaygroundFile(client, path, content) {
        return client.mkdirTree(dirname(path)).then(function () {
            return client.writeFile(path, content);
        });
    }

    function syncPlaygroundFile(client, mirror, path, content) {
        content = String(content || '');
        if (mirror.files[path] === content) {
            return Promise.resolve();
        }

        return writePlaygroundFile(client, path, content).then(function () {
            mirror.files[path] = content;
        });
    }

    function syncPlaygroundPreflightFiles(client, payload, proposedContent) {
        var mirror = getPlaygroundMirror(payload);
        var writes = [];

        (payload.files || []).forEach(function (file) {
            writes.push(syncPlaygroundFile(
                client,
                mirror,
                payload.wp_root + '/' + file.path,
                file.content || ''
            ));
        });

        writes.push(syncPlaygroundFile(
            client,
            mirror,
            payload.wp_root + '/' + payload.target_relative_path,
            proposedContent || ''
        ));

        return Promise.all(writes);
    }

    function runPlaygroundBoot(client, payload) {
        var code = '<?php\n'
            + 'require_once \'/wordpress/wp-load.php\';' + '\n';

        if (payload.boot === 'plugin' && payload.entry_file) {
            code += 'require_once ABSPATH . \'wp-admin/includes/plugin.php\';' + '\n'
                + '$result = activate_plugin(' + phpString(payload.entry_file) + ');' + '\n'
                + 'if ( is_wp_error( $result ) ) { echo \'HAYDI_PLAYGROUND_ERROR: \' . $result->get_error_message(); return; }' + '\n';
        } else if (payload.boot === 'theme' && payload.entry_file) {
            code += 'switch_theme(' + phpString(payload.entry_file) + ');' + '\n';
        }

        code += 'echo "HAYDI_PLAYGROUND_OK";';

        return client.run({ code: code }).then(function (response) {
            var text = playgroundResponseText(response);
            if (text.indexOf('HAYDI_PLAYGROUND_ERROR:') !== -1) {
                throw new Error(text.substring(text.indexOf('HAYDI_PLAYGROUND_ERROR:') + 31).trim());
            }
            if (text.indexOf('HAYDI_PLAYGROUND_OK') === -1) {
                throw new Error('Playground did not finish bootstrapping WordPress.');
            }
            return client.request({ url: '/', method: 'GET', headers: {} });
        }).then(function (response) {
            assertPlaygroundResponse(response, 'Playground front page');
            return client.request({ url: '/wp-admin/', method: 'GET', headers: {} });
        }).then(function (response) {
            assertPlaygroundResponse(response, 'Playground admin');
        });
    }

    function runPlaygroundPreflight(cfg, p) {
        if (!isPlaygroundPreflightEnabled()) {
            return Promise.resolve('disabled');
        }

        var targetPath = getProposedWritePath(cfg, p);
        if (!isPhpPath(targetPath)) {
            return Promise.resolve('skipped');
        }

        return Promise.all([
            getProposedWriteContent(cfg, p),
            preparePlaygroundPayload(targetPath),
            getPlaygroundClient(),
        ]).then(function (results) {
            var proposedContent = results[0];
            var payload = results[1];
            var client = results[2];

            return syncPlaygroundPreflightFiles(client, payload, proposedContent).then(function () {
                return runPlaygroundBoot(client, payload);
            });
        });
    }

    function setupProposal(cfg) {
        cfg.show = cfg.show || defaultShow(cfg);
        cfg.hide = cfg.hide || defaultHide(cfg);
        cfg.cancelUi = cfg.cancelUi || 'I cancelled the proposed ' + cfg.label + '.';
        cfg.cancelAi = cfg.cancelAi || 'The user cancelled this ' + cfg.label + '. Please reconsider or ask for clarification.';

        $('#' + cfg.confirmBtnId).on('click', function () {
            var p = state.pending;
            if (!p || p.kind !== cfg.kind) return;

            if (cfg.confirmText && !maybeConfirm(cfg.confirmText(p))) { return; }

            $('#' + cfg.statusId).text('Working\u2026').removeClass('is-error');

            var ajaxAction = cfg.getAjaxAction ? cfg.getAjaxAction(p) : cfg.ajaxAction;
            var postPayload = cfg.buildPayload ? cfg.buildPayload(p) : buildPayload(cfg, p);

            var applyAfterPreflight = function () {
                trackApply(post(ajaxAction, postPayload, function (res) {
                    if (res.success) {
                        recordApply(cfg.kind);
                        var aiMessage;
                        if (cfg.onSuccess) {
                            aiMessage = cfg.onSuccess(p, res.data);
                        } else {
                            $('#' + cfg.statusId).text('Done.');
                            appendMessage('assistant', cfg.successUi(p, res.data));
                            aiMessage = cfg.successAi(p, res.data);
                        }
                        if (cfg.onApplied) { cfg.onApplied(p, res.data); }

                        state.messages.push({
                            role:    'user',
                            content: (p.pre_results || []).concat([{
                                type:        'tool_result',
                                tool_use_id: p.tool_use_id,
                                name:        p.tool_name,
                                content:     aiMessage,
                            }]),
                        });

                        hideAllProposals();
                        runChatRequest();
                    } else {
                        var errMsg = (res.data && res.data.message) ? res.data.message : 'Failed.';
                        var errOutput = (res.data && res.data.output) ? '\nOutput: ' + res.data.output : '';
                        var toolErrContent = 'Error: ' + errMsg + errOutput;
                        appendMessage('error', toolErrContent);

                        state.messages.push({
                            role:    'user',
                            content: (p.pre_results || []).concat([{
                                type:        'tool_result',
                                tool_use_id: p.tool_use_id,
                                name:        p.tool_name,
                                content:     toolErrContent,
                            }]),
                        });
                        hideAllProposals();
                        runChatRequest();
                    }
                })).fail(function (jqXHR) {
                    var failMsg = ajaxFailureMessage(jqXHR, 'Request failed.');
                    var toolFailContent = 'Error: ' + failMsg;
                    appendMessage('error', toolFailContent);

                    state.messages.push({
                        role:    'user',
                        content: (p.pre_results || []).concat([{
                            type:        'tool_result',
                            tool_use_id: p.tool_use_id,
                            name:        p.tool_name,
                            content:     toolFailContent,
                        }]),
                    });
                    hideAllProposals();
                    runChatRequest();
                });
            };

            if (cfg.kind === 'write' || cfg.kind === 'edit') {
                $('#' + cfg.statusId).text('Preflighting in WordPress Playground\u2026');
                state.applyInFlight = true;
                runPlaygroundPreflight(cfg, p)
                    .then(function (result) {
                        state.applyInFlight = false;
                        if (result === 'skipped' || result === 'disabled') {
                            $('#' + cfg.statusId).text('Working\u2026').removeClass('is-error');
                        } else {
                            $('#' + cfg.statusId).text('Playground boot passed. Applying\u2026').removeClass('is-error');
                        }
                        applyAfterPreflight();
                    })
                    .catch(function (err) {
                        state.applyInFlight = false;
                        $('#' + cfg.statusId).text('Playground preflight failed: ' + (err && err.message ? err.message : 'Unknown error.')).addClass('is-error');
                    });
                return;
            }

            applyAfterPreflight();
        });

        $('#' + cfg.cancelBtnId).on('click', function () {
            var p = state.pending;
            if (!p || p.kind !== cfg.kind) return;

            state.messages.push({
                role:    'user',
                content: (p.pre_results || []).concat([{
                    type:        'tool_result',
                    tool_use_id: p.tool_use_id,
                    name:        p.tool_name,
                    content:     cfg.cancelAi,
                }]),
            });
            appendMessage('user', cfg.cancelUi);
            hideAllProposals();
            runChatRequest();
        });
    }

    function hideAllProposals() {
        Object.keys(PROPOSALS).forEach(function (k) { PROPOSALS[k].hide(); });
        state.pending = null;
    }

    Object.keys(PROPOSALS).forEach(function (k) { setupProposal(PROPOSALS[k]); });

    // -------------------------------------------------------------------------
    // Chat DOM helpers
    // -------------------------------------------------------------------------

    // Format a Unix-ms timestamp as a short caption: "HH:MM" for today, otherwise
    // include an abbreviated date. Returns '' when the input is missing/invalid.
    function formatTimestamp(ms) {
        if (!ms) return '';
        var d   = new Date(Number(ms));
        if (isNaN(d.getTime())) return '';
        var now = new Date();
        var sameDay = d.getFullYear() === now.getFullYear()
                   && d.getMonth()    === now.getMonth()
                   && d.getDate()     === now.getDate();
        var time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        if (sameDay) return time;
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
    }

    // Configure marked: escape raw HTML blocks (security), handle wpc-view:
    // links as inline file-viewer buttons, and apply existing CSS classes to
    // code blocks and tables so they match the rest of the chat UI.
    marked.use({
        renderer: {
            html: function (token) {
                return esc(token.text || token.raw || '');
            },
            link: function (token) {
                var href  = token.href || '';
                var label = esc(token.text || href);
                if (href.indexOf('wpc-view:') === 0) {
                    var path = href.slice('wpc-view:'.length);
                    return '<button type="button" class="wpc-view-toggle" data-path="' + esc(path) + '">' + label + '</button>';
                }
                if (href.indexOf('https://') === 0 || href.indexOf('http://') === 0) {
                    return '<a href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
                }
                if (href.indexOf('/') === 0) {
                    return '<a href="' + esc(href) + '">' + label + '</a>';
                }
                return label;
            }
        }
    });

    function renderAssistantMarkdown(text) {
        return marked.parse(text).trimEnd();
    }

    // Scroll the chat container so a freshly-expanded view block is visible.
    // scrollIntoView with block:'nearest' pans the chat just enough to bring
    // the top of the viewer into view without yanking the user off the bottom
    // of a long chat.
    function scrollViewBlockIntoView($block) {
        if ($block && $block.length) {
            $block[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    // Click handler for wpc-view: links. Toggles a read-only <pre> just after
    // the button's chat message; first click fetches via the existing
    // read_file AJAX, subsequent clicks just hide/show the cached result.
    // The created block is stored on the button via jQuery's .data() so we
    // don't have to round-trip the path through a CSS selector.
    $('#wpc-chat-messages').on('click', '.wpc-view-toggle', function () {
        var $btn   = $(this);
        var $block = $btn.data('viewBlock');

        if ($block && $block.length) {
            $block.toggleClass('wpc-hidden');
            if (!$block.hasClass('wpc-hidden')) { scrollViewBlockIntoView($block); }
            return;
        }

        $block = $('<div class="wpc-view-block"><pre class="wpc-view-pre"></pre></div>')
            .insertAfter($btn.closest('.wpc-message'));
        $btn.data('viewBlock', $block);
        var $pre = $block.find('.wpc-view-pre').html(spinner() + ' Loading\u2026');
        scrollViewBlockIntoView($block);

        post('haydi_read_file', { path: $btn.data('path') }, function (res) {
            if (res.success) {
                $pre.text(res.data.content);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : 'Failed to read file.';
                $pre.text(msg).addClass('is-error');
            }
            scrollViewBlockIntoView($block);
        }).fail(function () {
            $pre.text('Request failed.').addClass('is-error');
        });
    });

    function renderMessage(role, text, extraClass, timestamp) {
        var roleClass = {
            user:      'wpc-message--user',
            assistant: 'wpc-message--assistant',
            error:     'wpc-message--error',
        }[role] || 'wpc-message--assistant';

        var $msg = $('<div class="wpc-message ' + roleClass + (extraClass ? ' ' + extraClass : '') + '"></div>');

        if (extraClass) {
            $msg.html(text); // Trusted internal HTML only (e.g. spinner).
        } else if (role === 'assistant') {
            $msg.html(renderAssistantMarkdown(text)); // Escapes text, allows wp-admin hrefs.
        } else {
            $msg.text(text); // Plain text for user input, errors, tool output.
        }

        $('#wpc-chat-messages').append($msg);

        // Skip the timestamp on transient UI (e.g. "Thinking…" spinner) and on
        // entries that lack a stamped time (legacy logs saved before timestamps
        // were added).
        if (!extraClass) {
            var label = formatTimestamp(timestamp);
            if (label) {
                var $time = $('<div class="wpc-message-time wpc-message-time--' + role + '"></div>')
                    .text(label);
                // Copy-to-clipboard for assistant responses — copies the raw
                // markdown source captured in this closure, so re-rendered
                // entries from displayLog also get a working button.
                if (role === 'assistant') {
                    var msgText = text;
                    var $copy   = $('<button type="button" class="wpc-message-copy" aria-label="Copy message to clipboard" title="Copy message"></button>')
                        .html(COPY_ICON_SVG)
                        .on('click', function () { copyToClipboard(msgText, $copy); });
                    $time.append(document.createTextNode(' · ')).append($copy);
                }
                $time.insertAfter($msg);
            }
        }

        scrollChatToBottom();
        return $msg;
    }

    // Wrapper that also records the message in the persistent display log.
    // Skipped when extraClass is set (transient UI like the "Thinking…" spinner).
    function appendMessage(role, text, extraClass) {
        var t = extraClass ? null : Date.now();
        var $msg = renderMessage(role, text, extraClass, t);
        if (!extraClass) {
            state.displayLog.push({ role: role, text: text, t: t });
        }
        return $msg;
    }

    function scrollChatToBottom() {
        var $c = $('#wpc-chat-messages');
        $c.scrollTop($c[0].scrollHeight);
    }

    // -------------------------------------------------------------------------
    // Jetpack notice dismissal
    // -------------------------------------------------------------------------

    $('#wpc-jetpack-notice-dismiss').on('click', function () {
        $('#wpc-jetpack-notice').remove();
        post('haydi_dismiss_jetpack_notice', {}, function () {});
    });

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    $('#wpc-btn-clear-log').on('click', function () {
        if (!confirm('Clear all audit log entries?')) return;

        post('haydi_clear_log', {}, function (res) {
            if (res.success) {
                $('#wpc-audit-tbody').html('<tr><td colspan="5">Log cleared.</td></tr>');
                $('.wpc-audit-section .tablenav.bottom').remove();
            }
        });
    });

    // -------------------------------------------------------------------------
    // Auto-accept toggle (session-only — resets on page reload)
    // -------------------------------------------------------------------------

    $('#wpc-auto-accept').on('change', function () {
        state.autoAccept = this.checked;
        $('#wpc-auto-accept-warning').toggleClass('wpc-hidden', !state.autoAccept);
    });

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    $('#wpc-max-tokens').val(haydi.maxTokens);
    $('#wpc-playground-preflight').prop('checked', isPlaygroundPreflightEnabled());
    $('#wpc-enable-tracks').prop('checked', isTracksEnabled());
    warmPlaygroundClient();

    function isPlaygroundPreflightEnabled() {
        return haydi.playgroundPreflightEnabled === true
            || haydi.playgroundPreflightEnabled === 1
            || haydi.playgroundPreflightEnabled === '1'
            || haydi.playgroundPreflightEnabled === 'true';
    }

    function isTracksEnabled() {
        return haydi.tracksEnabled === true
            || haydi.tracksEnabled === 1
            || haydi.tracksEnabled === '1'
            || haydi.tracksEnabled === 'true';
    }

    var saveSettingsTimer;
    function saveSettings(commit) {
        var $status = $('#wpc-tokens-status').text('').removeClass('is-error');
        var $playgroundStatus = $('#wpc-playground-status').text('').removeClass('is-error');
        post('haydi_save_settings', {
            max_tokens:                   $('#wpc-max-tokens').val(),
            playground_preflight_enabled: $('#wpc-playground-preflight').is(':checked') ? '1' : '0',
            enable_tracks:                $('#wpc-enable-tracks').is(':checked') ? '1' : '0',
            commit:                       commit ? '1' : '0',
        }, function (res) {
            if (!res.success) {
                $status.addClass('is-error').text((res.data && res.data.message) || 'Error.');
                $playgroundStatus.addClass('is-error').text('Error.');
                window.setTimeout(function () { $status.text('').removeClass('is-error'); }, 6000);
                window.setTimeout(function () { $playgroundStatus.text('').removeClass('is-error'); }, 6000);
                updateContextBadge();
                return;
            }
            haydi.maxTokens = Number($('#wpc-max-tokens').val()) || haydi.maxTokens;
            haydi.playgroundPreflightEnabled = !!(res.data && res.data.playground_preflight_enabled);
            haydi.tracksEnabled = !!(res.data && res.data.enable_tracks);
            if (isPlaygroundPreflightEnabled()) {
                warmPlaygroundClient();
            } else {
                resetPlaygroundMirror();
            }
            updateContextBadge();
        }).fail(function () {
            $status.addClass('is-error').text('Request failed.');
            $playgroundStatus.addClass('is-error').text('Request failed.');
            updateContextBadge();
        });
    }

    // Debounce typing so a flurry of keystrokes collapses into one POST; commit
    // immediately on blur/Enter via the native `change` event.
    $('#wpc-max-tokens').on('input', function () {
        window.clearTimeout(saveSettingsTimer);
        updateContextBadge();
        saveSettingsTimer = window.setTimeout(function () { saveSettings(false); }, 600);
    }).on('change', function () {
        window.clearTimeout(saveSettingsTimer);
        updateContextBadge();
        saveSettings(true);
    });

    $('#wpc-playground-preflight').on('change', function () {
        window.clearTimeout(saveSettingsTimer);
        saveSettings(true);
    });

    $('#wpc-enable-tracks').on('change', function () {
        window.clearTimeout(saveSettingsTimer);
        saveSettings(true);
    });

    // -------------------------------------------------------------------------
    // Small utilities
    // -------------------------------------------------------------------------

    updateUsageBadge();
    updateContextBadge();
    if (!$('#wpc-chat-input').prop('disabled')) {
        $('#wpc-chat-input').trigger('focus');
    }

    // -------------------------------------------------------------------------
    // Restore chat from ?chat=<id> on page load
    // -------------------------------------------------------------------------

    (function restoreChatFromUrl() {
        var id = new URLSearchParams(window.location.search).get('chat');
        if (id) {
            // Resuming an existing chat — chips would only clutter
            // the loaded history, so leave them off.
            loadChat(id, { silent: true });
        } else {
            renderSuggestionChips();
        }
    }());

    // -------------------------------------------------------------------------
    // Welcome / onboarding modal
    // -------------------------------------------------------------------------

    (function initWelcomeModal() {
        var $overlay = $('#wpc-welcome-overlay');
        if (!$overlay.length) { return; }

        $('#wpc-welcome-confirm').on('click', function () {
            var enableTracks = $('#wpc-welcome-enable-tracks').is(':checked');
            post('haydi_complete_onboarding', { enable_tracks: enableTracks }, function (res) {
                if (res.success && res.data && res.data.enable_tracks) {
                    $('#wpc-enable-tracks').prop('checked', true);
                }
            });
            $overlay.remove();
        });
    }());

    // -------------------------------------------------------------------------
    // Remote Access — API token management
    // -------------------------------------------------------------------------

    (function initTokenManager() {
        var $list    = $('#wpc-tokens-list');
        var $display = $('#wpc-new-token-display');
        var $value   = $('#wpc-new-token-value');
        var $snippet = $('#wpc-mcp-config-snippet');

        if (!$list.length) { return; }

        function esc(str) {
            return $('<span>').text(String(str)).html();
        }

        function renderTokens(tokens) {
            if (!tokens || !tokens.length) {
                $list.html('<p class="wpc-muted" style="font-size:12px;margin:0;">No tokens yet.</p>');
                return;
            }
            var html = '';
            tokens.forEach(function (t) {
                var date = t.created ? new Date(t.created * 1000).toLocaleDateString() : '';
                html += '<div style="display:flex;align-items:center;gap:4px;margin-bottom:4px;font-size:11px;">';
                html += '<code style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(t.label) + '">' + esc(t.prefix) + '&hellip;</code>';
                html += '<span class="wpc-muted" style="white-space:nowrap;">' + esc(t.label) + '</span>';
                if (date) {
                    html += '<span class="wpc-muted" style="white-space:nowrap;">' + esc(date) + '</span>';
                }
                html += '<button type="button" class="wpc-btn-revoke-token button button-link-delete" style="padding:0;" data-hash="' + esc(t.hash) + '">Revoke</button>';
                html += '</div>';
            });
            $list.html(html);
        }

        function showNewToken(token) {
            $value.text(token);
            if ($snippet.length) {
                var mcpUrl = $snippet.data('mcp-url') || (window.location.origin + '/wp-json/haydi/v1/mcp');
                var config = JSON.stringify({
                    mcpServers: {
                        haydi: {
                            type: 'http',
                            url: mcpUrl,
                            headers: { Authorization: 'Bearer ' + token }
                        }
                    }
                }, null, 2);
                $snippet.text(config);
            }
            $display.removeClass('wpc-hidden');
        }

        // Load existing tokens on page load.
        post('haydi_list_tokens', {}, function (res) {
            if (res.success) { renderTokens(res.data.tokens); }
        });

        // Generate a new token.
        $('#wpc-btn-generate-token').on('click', function () {
            var label = String($('#wpc-token-label').val() || '');
            post('haydi_generate_token', { label: label }, function (res) {
                if (res.success) {
                    renderTokens(res.data.tokens);
                    showNewToken(res.data.token);
                    $('#wpc-token-label').val('');
                }
            });
        });

        // Copy the displayed token to the clipboard.
        $('#wpc-btn-copy-token').on('click', function () {
            var token = $value.text();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(token).then(function () {
                    var $btn = $('#wpc-btn-copy-token');
                    $btn.text('Copied!');
                    window.setTimeout(function () { $btn.text('Copy to clipboard'); }, 2000);
                });
            } else {
                window.getSelection().selectAllChildren($value[0]);
            }
        });

        // Revoke a token (event delegation so it works after re-render).
        $list.on('click', '.wpc-btn-revoke-token', function () {
            var hash = String($(this).data('hash') || '');
            if (!hash || !window.confirm('Revoke this token? Any tool using it will lose access immediately.')) {
                return;
            }
            post('haydi_revoke_token', { hash: hash }, function (res) {
                if (res.success) {
                    renderTokens(res.data.tokens);
                    $display.addClass('wpc-hidden');
                }
            });
        });
    }());

}(jQuery));
