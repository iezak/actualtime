/* global CFG_GLPI, GLPI_PLUGINS_PATH, bootstrap */
/* SPDX-License-Identifier: AGPL-3.0-or-later */
window.actualTime = new function() {
   const self = this;
   const counters = new Map();
   const states = new Map();
   const pending = new Set();
   const discovered = new WeakSet();
   const editedStatuses = new WeakSet();
   const newTargets = new Set();
   let active = [];
   let popupEnabled = false;
   let toast = null;
   let modal = null;
   let modalRequest = 0;
   let messageId = 0;
   let generation = 0;
   let syncing = false;
   let syncQueued = false;
   let initialized = false;
   let popupKeys = '';
   let popupDismissed = false;
   const labels = {
      symb_d: '%dd', symb_day: '%d day', symb_days: '%d days',
      symb_h: '%dh', symb_hour: '%d hour', symb_hours: '%d hours',
      symb_min: '%dmin', symb_minute: '%d minute', symb_minutes: '%d minutes',
      symb_s: '%ds', symb_second: '%d second', symb_seconds: '%d seconds',
      text_pause: 'Pause', text_start: 'Start', text_restart: 'Resume', text_stop: 'Stop', text_done: 'Done',
      text_running: 'Running tasks (%d)', text_task: 'Task #%d',
      text_error: 'Could not update the timer. Refresh the task and try again.'
   };

   function key(task, type) {
      return /^(TicketTask|ChangeTask|ProblemTask|ProjectTask)$/.test(type)
         && Number.isSafeInteger(Number(task)) && Number(task) > 0 ? type + ':' + Number(task) : '';
   }
   function elements(task, type, role) {
      if (!key(task, type)) return $();
      return $("[id^='actualtime_" + role + "_" + type + "_" + Number(task) + "_']");
   }
   function elapsed(entry) {
      return entry.time + Math.max(0, Math.floor((Date.now() - entry.at) / 1000));
   }
   function draw(task, type, time) {
      const value = self.timeToText(Math.max(0, Math.floor(Number(time) || 0)), 1);
      elements(task, type, 'timer').text(value);
      $("[data-actualtime-popup-time='" + key(task, type) + "']").text(value);
   }
   function tick() {
      counters.forEach((entry) => draw(entry.task, entry.type, elapsed(entry)));
   }
   function taskForms(task, type) {
      return $('form').filter(function() {
         const form = $(this);
         if (!form.find("input[name='id']").filter(function() { return Number(this.value) === Number(task); }).length) return false;
         const action = (form.attr('action') || '').split('?')[0].toLowerCase();
         return action.endsWith('/' + type.toLowerCase() + '.form.php')
            || form.closest("div[data-itemtype='" + type + "'][data-items-id='" + task + "']").length
            || form.closest('#actualtime_modal_content').attr('data-actualtime-task') === key(task, type);
      });
   }
   function trackedValue(fields, value) {
      fields.each(function() {
         const field = $(this);
         const previous = field.attr('data-track-changes');
         field.attr('data-track-changes', '').val(value).trigger('change');
         if (previous === undefined) field.removeAttr('data-track-changes');
         else field.attr('data-track-changes', previous);
      });
   }
   function updateCompletedForm(task, type, result) {
      const forms = taskForms(task, type);
      if (type !== 'ProjectTask') trackedValue(forms.find("select[name='state']"), 2);
      if (result.task_time !== undefined && result.task_time !== null) {
         trackedValue(forms.find("select[name='actiontime'], input[name='actiontime'], select[name='effective_duration'], input[name='effective_duration']"), result.task_time);
      }
      const scope = $("div[data-itemtype='" + type + "'][data-items-id='" + task + "']");
      scope.find('span.state.state_1').attr('title', labels.text_done).removeClass('state_1').addClass('state_2');
      if (result.task_time !== undefined && result.task_time !== null) scope.find('span.actiontime').text(self.timeToText(Number(result.task_time), 1));
   }
   function updateParentStatus(state) {
      const status = state.parent_status;
      if (state.parent_type !== 'Ticket' || !status || !Number.isSafeInteger(status.value)
         || status.value <= 0 || typeof status.label !== 'string' || typeof status.icon !== 'string') return;
      $('#itil-form').filter(function() {
         const form = $(this);
         const action = (form.attr('action') || '').split('?')[0].toLowerCase();
         return (action.endsWith('/ticket.form.php') || action.endsWith('/tracking.injector.php'))
            && form.children("input[name='id']").filter(function() { return Number(this.value) === Number(state.parent_id); }).length;
      }).each(function() {
         const form = $(this);
         const fields = form.find("select[name='status']");
         fields.each(function() {
            const field = $(this);
            if (editedStatuses.has(this) || String(field.val()) === String(status.value)) return;
            if (status.options && typeof status.options === 'object') {
               field.empty();
               Object.entries(status.options).forEach(([value, label]) => {
                  if (/^[1-9][0-9]*$/.test(value) && typeof label === 'string') field.append(new Option(label, value));
               });
            }
            if (!field.find('option').filter(function() { return Number(this.value) === status.value; }).length) {
               field.append(new Option(status.label, String(status.value)));
            }
            // Refresh Select2 without simulating a user edit or opening dialogs.
            field.val(String(status.value)).trigger('change.select2');
         });
         const icon = () => $('<i>', {class: status.icon + ' me-1', title: status.label, 'data-bs-toggle': 'tooltip'});
         form.find('#heading-main-item .status-recall').empty().append(icon());
         if (!fields.length) {
            const container = form.find(".form-field > label[for^='status_']").closest('.form-field').children('.field-container').first();
            if (!container.length) return;
            let value = container.children('[data-actualtime-parent-status]');
            if (!value.length) {
               // Keep GLPI's reopen link and any other controls in this field.
               container.contents().filter(function() {
                  return this.nodeType === 3 || (this.nodeType === 1 && $(this).is('i.itilstatus'));
               }).remove();
               value = $('<span>', {'data-actualtime-parent-status': '1'}).prependTo(container);
            }
            value.empty().append(icon()).append(document.createTextNode(status.label));
         }
      });
   }
   function currentTicket() {
      const form = $('#itil-form');
      const action = (form.attr('action') || '').split('?')[0].toLowerCase();
      if (!action.endsWith('/ticket.form.php') && !action.endsWith('/tracking.injector.php')) return 0;
      const id = Number(form.children("input[name='id']").first().val());
      return Number.isSafeInteger(id) && id > 0 ? id : 0;
   }
   function timelineRequest(settings) {
      const ticket = currentTicket();
      if (!ticket) return false;
      const url = new URL(settings.url, window.location.href);
      if (url.origin !== window.location.origin) return false;
      const root = (CFG_GLPI.root_doc || '').replace(/\/$/, '');
      const path = url.pathname;
      const method = (settings.type || settings.method || 'GET').toUpperCase();
      const data = typeof settings.data === 'string' ? new URLSearchParams(settings.data) : settings.data;
      const field = name => {
         const value = data && typeof data.get === 'function' ? data.get(name) : data && data[name];
         return value === undefined || value === null ? url.searchParams.get(name) : value;
      };
      const parent = field('parenttype') || field('itemtype');
      const parentId = field('tickets_id') || (parent === 'Ticket' ? field('items_id') : null);
      if ((parent && parent !== 'Ticket') || (parentId !== null && Number(parentId) !== ticket)) return false;
      if (path === root + '/ajax/timeline.php') {
         return (method === 'POST' && field('action') === 'change_task_state')
            || (field('action') === 'viewsubitem' && ['TicketTask', 'ITILFollowup'].includes(field('type')));
      }
      return method === 'POST' && [
         '/front/tickettask.form.php', '/front/commonitiltask.form.php', '/front/itilfollowup.form.php'
      ].some(endpoint => path === root + endpoint);
   }
   function applyState(state, force) {
      const id = key(state.task_id, state.itemtype);
      if (!id || (!force && pending.has(id))) return;
      states.set(id, state);
      updateParentStatus(state);
      if (state.active) self.startCount(state.task_id, state.itemtype, state.time);
      else {
         self.endCount(state.task_id, state.itemtype);
         draw(state.task_id, state.itemtype, state.time);
      }
      elements(state.task_id, state.itemtype, 'timer').css('color', state.active ? 'red' : 'black');
      elements(state.task_id, state.itemtype, 'faclock').css('color', state.active ? 'red' : 'black');
      const toggle = elements(state.task_id, state.itemtype, 'button').filter("[id^='actualtime_button_" + state.itemtype + "_" + Number(state.task_id) + "_1_']");
      const stop = elements(state.task_id, state.itemtype, 'button').filter("[id^='actualtime_button_" + state.itemtype + "_" + Number(state.task_id) + "_2_']");
      const allowed = Boolean(state.can_control);
      const toggleEnabled = allowed && !state.done && (state.active || state.can_start);
      const resume = Number(state.time) > 0;
      toggle.attr('action', state.active ? 'pause' : 'start').prop('disabled', !toggleEnabled)
         .attr('title', state.active ? labels.text_pause : (resume ? labels.text_restart : labels.text_start))
         .css('background-color', toggleEnabled ? (state.active ? 'orange' : 'green') : 'gray')
         .html("<span><i class='fa-solid " + (state.active ? 'fa-pause' : (resume ? 'fa-forward' : 'fa-play')) + "' aria-hidden='true'></i></span>");
      const stopEnabled = allowed && !state.done && (state.active || Number(state.time) > 0);
      stop.attr('action', 'end').prop('disabled', !stopEnabled).attr('title', labels.text_stop)
         .css('background-color', stopEnabled ? 'red' : 'gray');
   }
   function renderPopup() {
      if (!toast) return;
      const nextKeys = active.map(state => key(state.task_id, state.itemtype)).join('|');
      if (nextKeys !== popupKeys) popupDismissed = false;
      popupKeys = nextKeys;
      const body = $('#toast_body').empty();
      if (!popupEnabled || !active.length || popupDismissed) {
         toast.hide();
         return;
      }
      $('<strong>').text(labels.text_running.replace('%d', active.length)).appendTo(body);
      const list = $('<ul>', {class: 'list-unstyled mb-0 mt-2'}).css({maxHeight: '18rem', overflowY: 'auto'}).appendTo(body);
      active.forEach(state => {
         const id = key(state.task_id, state.itemtype);
         if (!id) return;
         const row = $('<li>', {class: 'mb-2'}).appendTo(list);
         $('<a>', {href: state.link}).text(state.name + ' #' + state.parent_id + ' / ' + labels.text_task.replace('%d', state.task_id))
            .on('click', function(e) {
               if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
               self.showTaskForm(e, state.task_id, state.itemtype);
            }).appendTo(row);
         row.append(document.createTextNode(' → '));
         $('<span>').attr('data-actualtime-popup-time', id).appendTo(row);
         const counter = counters.get(id);
         draw(state.task_id, state.itemtype, counter ? elapsed(counter) : state.time);
      });
      toast.show();
   }
   function notify(result) {
      const warning = result.type === 'warning';
      const title = warning ? __('Warning') : (result.type === 'info' ? _n('Information', 'Informations', 1) : __('Error'));
      const container = $('<div>', {class: 'toast-container bottom-0 end-0 p-3 messages_after_redirect'}).appendTo('body');
      const root = $('<div>', {id: 'actualtime_message_' + (++messageId), class: 'toast border-0', role: 'alert'}).appendTo(container);
      const header = $('<div>', {class: 'toast-header text-white ' + (warning ? 'bg-warning' : 'bg-info')}).appendTo(root);
      $('<strong>', {class: 'me-auto'}).text(title).appendTo(header);
      $('<button>', {type: 'button', class: 'btn-close', 'data-bs-dismiss': 'toast', 'aria-label': __('Close')}).appendTo(header);
      $('<div>', {class: 'toast-body'}).html(result.message || labels.text_error).appendTo(root);
      root.on('hidden.bs.toast', () => container.remove());
      new bootstrap.Toast(root[0], {delay: 10000}).show();
   }

   this.timeToText = function(time, format) {
      var days = 0;
      var hours = 0;
      var minutes = 0;
      var distance = time;
      var seconds = distance % 60;
      distance -= seconds;
      var text = (format == 3 ? (seconds > 1 ? labels.symb_seconds : labels.symb_second) : labels.symb_s).replace('%d', seconds);
      ;
      if (distance > 0) {
         minutes = (distance % 3600) / 60;
         distance -= minutes * 60;
         text = (format == 3 ? (minutes > 1 ? labels.symb_minutes : labels.symb_minute) : labels.symb_min).replace('%d', minutes) + ' ' + text;
         if (distance > 0) {
            if (format == 2) {
               hours = distance / 3600;
               if (minutes < 10) {
                  minutes = '0' + minutes;
               }
               return labels.symb_h.replace('%d', hours) + (seconds > 0 ? labels.symb_min.replace('%d', minutes) + labels.symb_s.replace('%d', (seconds < 10 ? '0' : '') + seconds) : minutes);
            }
            hours = (distance % 86400) / 3600;
            distance -= hours * 3600;
            text = (format == 3 ? (hours > 1 ? labels.symb_hours : labels.symb_hour) : labels.symb_h).replace('%d', hours) + ' ' + text;
            if (distance > 0) {
               days = distance / 86400;
               text = (format == 3 ? (days > 1 ? labels.symb_days : labels.symb_day) : labels.symb_d).replace('%d', days) + ' ' + text;
            }
         }
      }
      return text;
   }

   this.startCount = function(task, type, time) {
      const id = key(task, type);
      if (id) {
         counters.set(id, {task: Number(task), type, time: Math.max(0, Number(time) || 0), at: Date.now()});
         draw(task, type, time);
      }
   };
   this.endCount = function(task, type) {
      counters.delete(key(task, type));
   };
   this.fillCurrentTime = function(task, type, time) {
      const counter = counters.get(key(task, type));
      draw(task, type, counter ? elapsed(counter) : time);
   };
   this.showTimerPopup = function() { self.refresh(); };
   this.showTaskForm = function(e, task, type) {
      if (!key(task, type)) return;
      if (e) e.preventDefault();
      if (!modal) {
         $('body').append("<div class='modal fade' id='modal_actualtime' role='dialog'><div class='modal-dialog modal-lg'><div id='actualtime_modal_content' class='modal-content'></div></div></div>");
         modal = new bootstrap.Modal(document.getElementById('modal_actualtime'), {});
      }
      const request = ++modalRequest;
      $('#actualtime_modal_content').attr('data-actualtime-task', key(task, type)).text(__('Loading...'));
      modal.show();
      $.ajax({type: 'GET', url: self.ajax_url, dataType: 'html', data: {showform: 1, task_id: task, itemtype: type}, cache: false})
         .done(html => {
            if (request === modalRequest) {
               $('#actualtime_modal_content').html(html);
               self.refresh();
            }
         }).fail(() => {
            if (request === modalRequest) $('#actualtime_modal_content').text(labels.text_error);
         });
   };
   this.pressedButton = function(task, type, action) {
      const id = key(task, type);
      if (!id || pending.has(id) || !['start', 'pause', 'end'].includes(action)) return;
      pending.add(id);
      ++generation;
      elements(task, type, 'button').prop('disabled', true);
      $.ajax({type: 'POST', url: self.ajax_url, dataType: 'json', data: {action, task_id: task, itemtype: type}})
         .done(result => {
            if (result.type === 'info' && result.runtime && key(result.task_id, result.itemtype) === id) {
               applyState(result.runtime, true);
               if (result.segment !== undefined) elements(task, type, 'segment').html(result.segment);
               if (action === 'end') updateCompletedForm(task, type, result);
               active = active.filter(state => key(state.task_id, state.itemtype) !== id);
               if (result.runtime.own_active) active.push(result.runtime);
               renderPopup();
               try { localStorage.setItem('actualtime:changed', String(Date.now())); } catch (error) { /* polling still reconciles tabs */ }
            } else notify(result);
         })
         .fail(() => notify({type: 'warning', message: labels.text_error}))
         .always(() => {
            pending.delete(id);
            ++generation;
            if (states.has(id)) applyState(states.get(id), true);
            self.refresh();
         });
   };
   this.refreshTimeline = function() {
      if (!currentTicket()) return;
      ++generation;
      $('[data-actualtime-task]').each(function() {
         newTargets.add($(this).attr('data-actualtime-task'));
      });
      self.refresh();
   };
   this.refresh = function() {
      if (!self.ajax_url) return;
      if (syncing) { syncQueued = true; return; }
      syncing = true;
      const atGeneration = generation;
      const ticket = currentTicket();
      const watch = new Set(counters.keys());
      $('[data-actualtime-task]').each(function() {
         if (!discovered.has(this)) {
            discovered.add(this);
            newTargets.add($(this).attr('data-actualtime-task'));
         }
      });
      newTargets.forEach(id => watch.add(id));
      const data = {footer: 1, watch: JSON.stringify(Array.from(watch).slice(0, 100))};
      if (ticket) data.ticket_id = ticket;
      $.ajax({type: 'GET', url: self.ajax_url, dataType: 'json', cache: false, data})
         .done(snapshot => {
            if (atGeneration !== generation || ticket !== currentTicket()) { syncQueued = true; return; }
            if (!Array.isArray(snapshot.states) || !Array.isArray(snapshot.active)) return;
            watch.forEach(id => newTargets.delete(id));
            Object.keys(labels).forEach(name => { if (typeof snapshot[name] === 'string') labels[name] = snapshot[name]; });
            popupEnabled = Boolean(snapshot.show_popup);
            active = snapshot.active.filter(state => state.own_active && key(state.task_id, state.itemtype));
            const returned = new Set();
            snapshot.states.forEach(state => {
               returned.add(key(state.task_id, state.itemtype));
               applyState(state, false);
            });
            counters.forEach((entry, id) => {
               if (!returned.has(id) && !pending.has(id)) {
                  counters.delete(id);
                  elements(entry.task, entry.type, 'timer').text('–');
                  elements(entry.task, entry.type, 'button').prop('disabled', true);
               }
            });
            if (snapshot.ticket) updateParentStatus(snapshot.ticket);
            renderPopup();
         })
         .always(() => {
            syncing = false;
            if (syncQueued) { syncQueued = false; self.refresh(); }
         });
   };
   this.init = function(ajax_url) {
      self.ajax_url = ajax_url;
      if (!initialized) {
         initialized = true;
         $(document).on('change.actualtimeParentStatus', "#itil-form select[name='status']", function() {
            editedStatuses.add(this);
         });
         $(document).on('ajaxSuccess.actualtimeTimeline', function(event, xhr, settings) {
            if (!timelineRequest(settings)) return;
            self.refreshTimeline();
            if ((settings.type || settings.method || 'GET').toUpperCase() === 'POST') {
               try { localStorage.setItem('actualtime:changed', String(Date.now())); } catch (error) { /* polling still reconciles tabs */ }
            }
         });
         $(document).on('click.actualtimeTimeline', '.close-edit-content', () => self.refreshTimeline());
         $(document).on('shown.bs.modal.actualtimeTimeline hidden.bs.modal.actualtimeTimeline', '#modal_actualtime', () => self.refreshTimeline());
         $('body').append("<div class='toast-container bottom-0 start-0 p-3 messages_after_redirect' id='toast_actualtime'><div class='toast border-0' role='status' aria-live='polite'><div class='toast-header bg-info text-white'><strong class='me-auto'>" + _n('Information', 'Informations', 1) + "</strong><button type='button' class='btn-close' data-bs-dismiss='toast' aria-label='" + __('Close') + "'></button></div><div id='toast_body' class='toast-body'></div></div></div>");
         toast = new bootstrap.Toast(document.querySelector('#toast_actualtime .toast'), {autohide: false});
         $('#toast_actualtime button[data-bs-dismiss]').on('click', () => { popupDismissed = true; });
         setInterval(tick, 1000);
         setInterval(() => { if (!document.hidden) self.refresh(); }, 10000);
         window.addEventListener('focus', () => self.refresh());
         window.addEventListener('storage', event => { if (event.key === 'actualtime:changed') self.refresh(); });
         document.addEventListener('visibilitychange', () => { if (!document.hidden) self.refresh(); });
      }
      self.refresh();
   };
}();

$(function() {
   window.actualTime.init(CFG_GLPI.root_doc + '/' + GLPI_PLUGINS_PATH.actualtime + '/ajax/timer.php');
});
