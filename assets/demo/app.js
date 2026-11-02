/*
 * DineReserve demo — diner flow controller.
 *
 * Screens: search → results → booking form → confirmation.
 * All rules live in engine.js; this file only renders and moves state.
 * Plain script (no ES modules), exposes nothing globally except through
 * window.DineReserveDemo (the namespace created by data.js / engine.js).
 */
(function (global) {
  'use strict';

  var NS = global.DineReserveDemo;
  if (!NS || !NS.Engine || !NS.data) {
    throw new Error('data.js and engine.js must be loaded before app.js');
  }

  var E = NS.Engine;
  var data = NS.data;
  var AREA_LABELS = E.areaLabels;
  var AREA_ORDER = E.areaOrder;
  var SCREENS = ['search', 'results', 'form', 'confirm'];

  var FIELD_ERRORS = {
    name: { input: 'bk-name', error: 'err-name' },
    email: { input: 'bk-email', error: 'err-email' },
    mobile: { input: 'bk-mobile', error: 'err-mobile' },
    special_requests: { input: 'bk-requests', error: 'err-special_requests' },
    policy: { input: 'bk-policy', error: 'err-policy' }
  };

  var state = {
    screen: 'search',
    query: null,
    result: null,
    slotTime: null,
    selectedTableId: null,
    formValues: null,
    lastBooking: null,
    lastElapsed: null,
    timer: { startedAt: null, stoppedAt: null, intervalId: null }
  };

  var el = {};
  var toastTimer = null;

  /* ================================================================== */
  /* DOM + formatting helpers                                            */
  /* ================================================================== */

  function $(id) {
    return document.getElementById(id);
  }

  function esc(value) {
    return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (character) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[character];
    });
  }

  function icon(name, extraClass) {
    return '<svg class="icon' + (extraClass ? ' ' + extraClass : '') +
      '" aria-hidden="true"><use href="#i-' + name + '"></use></svg>';
  }

  function plural(count, one, many) {
    return count + ' ' + (count === 1 ? one : many);
  }

  function guests(count) {
    return plural(count, 'guest', 'guests');
  }

  function areaLabel(area) {
    return AREA_LABELS[area] || area;
  }

  function dateAsNoon(isoDate) {
    // Noon keeps the calendar date stable in every time zone.
    return new Date(isoDate + 'T12:00:00+08:00');
  }

  function dateLong(isoDate) {
    try {
      return new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: data.restaurant.timezone
      }).format(dateAsNoon(isoDate));
    } catch (error) {
      return isoDate;
    }
  }

  function dateShort(isoDate) {
    try {
      return new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: data.restaurant.timezone
      }).format(dateAsNoon(isoDate));
    } catch (error) {
      return isoDate;
    }
  }

  function parseISO(iso) {
    if (typeof iso !== 'string') {
      return null;
    }
    var match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/.exec(iso);
    if (!match) {
      return null;
    }
    return { date: match[1], time: match[2] };
  }

  function dateTimeFromISO(iso) {
    var parts = parseISO(iso);
    return parts ? dateShort(parts.date) + ', ' + parts.time : String(iso || '');
  }

  function firstError(details) {
    var keys = Object.keys(details || {});
    return keys.length > 0 ? details[keys[0]] : 'Please check the highlighted fields.';
  }

  /* ================================================================== */
  /* Screen / focus / announcements                                      */
  /* ================================================================== */

  function showScreen(name) {
    for (var i = 0; i < SCREENS.length; i++) {
      el.screens[SCREENS[i]].hidden = SCREENS[i] !== name;
    }
    state.screen = name;
    document.body.setAttribute('data-screen', name);
    global.scrollTo(0, 0);
    var heading = el.screens[name].querySelector('[data-screen-heading]');
    if (heading) {
      try {
        heading.focus({ preventScroll: true });
      } catch (error) {
        heading.focus();
      }
    }
  }

  function announce(message) {
    el.liveRegion.textContent = '';
    global.setTimeout(function () {
      el.liveRegion.textContent = message;
    }, 30);
  }

  function toast(message) {
    el.toast.textContent = message;
    el.toast.hidden = false;
    if (toastTimer !== null) {
      global.clearTimeout(toastTimer);
    }
    toastTimer = global.setTimeout(function () {
      el.toast.hidden = true;
    }, 4200);
  }

  /* ================================================================== */
  /* Timer (URS KPI: one booking in under 60 seconds)                    */
  /* ================================================================== */

  function timerStarted() {
    return state.timer.startedAt !== null;
  }

  function elapsedSeconds() {
    if (state.timer.startedAt === null) {
      return null;
    }
    var end = state.timer.stoppedAt !== null ? state.timer.stoppedAt : Date.now();
    return Math.max(1, Math.round((end - state.timer.startedAt) / 1000));
  }

  function updateTimer() {
    var seconds = elapsedSeconds();
    el.timerValue.textContent = (seconds === null ? 0 : seconds) + ' s';
  }

  function startTimer() {
    if (state.timer.startedAt !== null) {
      return;
    }
    state.timer.startedAt = Date.now();
    state.timer.stoppedAt = null;
    el.timerChip.hidden = false;
    el.timerChip.classList.remove('is-done');
    updateTimer();
    state.timer.intervalId = global.setInterval(updateTimer, 1000);
  }

  function stopTimer() {
    if (state.timer.startedAt === null) {
      return;
    }
    if (state.timer.stoppedAt === null) {
      state.timer.stoppedAt = Date.now();
    }
    if (state.timer.intervalId !== null) {
      global.clearInterval(state.timer.intervalId);
      state.timer.intervalId = null;
    }
    updateTimer();
    el.timerChip.classList.add('is-done');
  }

  function resetTimer() {
    if (state.timer.intervalId !== null) {
      global.clearInterval(state.timer.intervalId);
    }
    state.timer = { startedAt: null, stoppedAt: null, intervalId: null };
    el.timerChip.hidden = true;
    el.timerChip.classList.remove('is-done');
    el.timerValue.textContent = '0 s';
  }

  /* ================================================================== */
  /* Search screen                                                       */
  /* ================================================================== */

  function renderVenuePeriods() {
    var periods = E.periods();
    var html = '';
    for (var i = 0; i < periods.length; i++) {
      html += '<li>' +
        '<span class="period-name">' + esc(periods[i].name) + '</span>' +
        '<span class="period-time">' + esc(periods[i].start) + ' – ' + esc(periods[i].end) + '</span>' +
        '<span class="period-duration">' + periods[i].avgDiningMinutes + ' min</span>' +
        '</li>';
    }
    el.venuePeriods.innerHTML = html;
  }

  function setSearchHint(message) {
    el.searchHint.textContent = message || '';
  }

  function updatePartyHint() {
    var party = parseInt(el.searchParty.value, 10);
    var largest = E.largestTableSeats();
    if (party > largest) {
      el.partyHint.hidden = false;
      el.partyHint.textContent = 'Our largest single table seats ' + largest +
        ' guests. You can still search, but for ' + guests(party) +
        ' please contact the restaurant — the team can combine tables.';
    } else {
      el.partyHint.hidden = true;
      el.partyHint.textContent = '';
    }
  }

  /**
   * Rebuild the start-time <select> for the chosen date. Start times already
   * past today are hidden so the demo can never offer a booking in the past.
   */
  function refreshTimeOptions(preferredTime, extraHint) {
    var date = el.searchDate.value;
    var groups = E.buildTimeOptions(date);
    el.searchTime.innerHTML = '';

    if (groups.length === 0) {
      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'No start times available';
      el.searchTime.appendChild(placeholder);
      el.searchTime.disabled = true;
      el.searchSubmit.disabled = true;
      setSearchHint(extraHint || 'No start times are left for this date. Try tomorrow.');
      return;
    }

    el.searchTime.disabled = false;
    el.searchSubmit.disabled = false;
    var values = [];
    for (var i = 0; i < groups.length; i++) {
      var group = groups[i];
      var optgroup = document.createElement('optgroup');
      optgroup.label = group.period.name + ' · ' + group.period.start + '–' +
        group.period.end + ' (last start ' + E.latestStart(group.period) + ')';
      for (var j = 0; j < group.starts.length; j++) {
        var option = document.createElement('option');
        option.value = group.starts[j];
        option.textContent = group.starts[j];
        optgroup.appendChild(option);
        values.push(group.starts[j]);
      }
      el.searchTime.appendChild(optgroup);
    }

    var wanted = preferredTime && values.indexOf(preferredTime) !== -1
      ? preferredTime
      : values[0];
    el.searchTime.value = wanted;

    if (extraHint) {
      setSearchHint(extraHint);
    } else if (date === E.todayISO()) {
      setSearchHint('Only start times still in the future today are shown.');
    } else {
      setSearchHint('Bookings are open up to 90 days ahead (Asia/Macau time).');
    }
  }

  function initSearchForm() {
    var today = E.todayISO();
    el.searchDate.min = today;
    el.searchDate.max = E.maxDateISO();

    var initialDate = today;
    var hint = '';
    if (E.buildTimeOptions(today).length === 0) {
      var tomorrow = E.addDays(today, 1);
      if (tomorrow <= E.maxDateISO()) {
        initialDate = tomorrow;
        hint = 'Today’s service has ended — showing tomorrow instead.';
      }
    }
    el.searchDate.value = initialDate;
    refreshTimeOptions(null, hint);
    updatePartyHint();
  }

  function showSearchError(message) {
    el.searchError.textContent = message;
    el.searchError.hidden = false;
    el.searchError.focus();
  }

  function hideSearchError() {
    el.searchError.hidden = true;
    el.searchError.textContent = '';
  }

  function errorText(error) {
    if (!error) {
      return 'Something went wrong. Please try again.';
    }
    var suffix = error.code && error.code !== 'VALIDATION_ERROR' ? ' (' + error.code + ')' : '';
    return (error.message || 'Something went wrong. Please try again.') + suffix;
  }

  function onSearchSubmit(event) {
    event.preventDefault();
    if (!timerStarted()) {
      startTimer();
    }
    hideSearchError();

    var date = el.searchDate.value;
    var time = el.searchTime.value;
    var party = parseInt(el.searchParty.value, 10);
    var area = el.searchArea.value || null;

    if (!date || !time) {
      showSearchError('Choose a date and a start time to check availability.');
      return;
    }

    try {
      var result = E.availability(date, time, party, area);
      state.query = { date: date, time: time, partySize: party, area: area };
      state.result = result;
      state.slotTime = time;
      state.selectedTableId = null;
      hideWaitlist();
      renderResults();
      showScreen('results');
      announce(availabilityAnnouncement(result, time, party));
    } catch (error) {
      showSearchError(errorText(error));
    }
  }

  function availabilityAnnouncement(result, time, party) {
    if (result.summary.available_tables > 0) {
      return plural(result.summary.available_tables, 'table', 'tables') +
        ' available at ' + time + ' for ' + guests(party) + '.';
    }
    return 'No tables available at ' + time + ' for ' + guests(party) + '. A waitlist option is offered.';
  }

  /* ================================================================== */
  /* Results screen                                                      */
  /* ================================================================== */

  function renderResults() {
    var query = state.query;
    var result = state.result;
    var period = result.service_period;

    el.resultsMeta.textContent = period.name + ' · ' + dateLong(query.date) + ' · ' +
      guests(query.partySize) + ' · ' + (query.area ? areaLabel(query.area) : 'Any area');
    el.slotPeriodLabel.textContent = period.start + '–' + period.end + ' · ' +
      period.slot_minutes + ' min per booking · last start ' +
      (result.slots.length > 0 ? result.slots[result.slots.length - 1].start : period.end);

    renderSummary();
    renderSlots();
    renderTables();
    hideWaitlist();
  }

  function renderSummary() {
    var result = state.result;
    var available = result.summary.available_tables;
    var party = state.query.partySize;

    el.resultsSummary.className = 'result-summary ' + (available > 0 ? 'is-available' : 'is-full');
    if (available > 0) {
      el.resultsSummary.innerHTML = icon('check') + '<span>' +
        plural(available, 'table', 'tables') + ' available at ' + esc(state.slotTime) +
        ' for ' + guests(party) + '</span>';
    } else {
      el.resultsSummary.innerHTML = icon('warning') + '<span>No tables for ' +
        guests(party) + ' at ' + esc(state.slotTime) + '</span>';
    }
  }

  function renderSlots() {
    var slots = state.result.slots;
    var html = '';
    for (var i = 0; i < slots.length; i++) {
      var slot = slots[i];
      var past = E.isPastStart(state.query.date, slot.start);
      var selected = slot.start === state.slotTime;
      var className = 'slot';
      var countText;

      if (past) {
        className += ' slot-past';
        countText = 'Past';
      } else if (slot.available_tables === 0) {
        className += ' slot-full';
        countText = 'Fully booked';
      } else {
        countText = plural(slot.available_tables, 'table', 'tables');
      }
      if (selected) {
        className += ' is-selected';
      }

      html += '<button type="button" class="' + className + '" data-time="' + esc(slot.start) +
        '" aria-pressed="' + (selected ? 'true' : 'false') + '"' +
        (past ? ' disabled' : '') + '>' +
        '<span class="slot-time">' + esc(slot.start) + '</span>' +
        '<span class="slot-count">' + esc(countText) + '</span>' +
        '</button>';
    }
    el.resultsSlots.innerHTML = html;
  }

  function updateSelectedSlotButtons() {
    var buttons = el.resultsSlots.querySelectorAll('button[data-time]');
    for (var i = 0; i < buttons.length; i++) {
      var selected = buttons[i].getAttribute('data-time') === state.slotTime;
      buttons[i].classList.toggle('is-selected', selected);
      buttons[i].setAttribute('aria-pressed', selected ? 'true' : 'false');
    }
  }

  function renderTables() {
    var query = state.query;
    var result = state.result;
    var tables = result.tables;
    var hasTables = tables.length > 0;

    el.resultsTablesCard.hidden = !hasTables;
    el.resultsEmpty.hidden = hasTables;
    el.tableContinue.disabled = true;
    el.tableContinue.textContent = 'Continue to booking';

    if (!hasTables) {
      renderEmptyState();
      return;
    }

    el.tablesHeading.textContent = 'Choose a table at ' + state.slotTime;
    el.tablesSub.textContent = plural(tables.length, 'matching table', 'matching tables') +
      ' · ' + guests(query.partySize) +
      ' · only tables that seat your party are listed.';
    el.resultsTables.innerHTML = buildTableGroups(tables);

    if (state.selectedTableId !== null) {
      var radio = $(('table-' + state.selectedTableId));
      if (radio) {
        radio.checked = true;
        updateTableSelection();
        el.tableContinue.disabled = false;
        updateContinueLabel();
      } else {
        state.selectedTableId = null;
      }
    }
  }

  function buildTableGroups(tables) {
    var groups = {};
    for (var i = 0; i < tables.length; i++) {
      var area = tables[i].area;
      if (!groups[area]) {
        groups[area] = [];
      }
      groups[area].push(tables[i]);
    }

    var html = '';
    for (var a = 0; a < AREA_ORDER.length; a++) {
      var areaName = AREA_ORDER[a];
      var list = groups[areaName];
      if (!list || list.length === 0) {
        continue;
      }
      list.sort(function (left, right) {
        return left.id - right.id;
      });
      html += '<fieldset class="area-group"><legend class="area-heading">' +
        esc(areaLabel(areaName)) + ' <span class="count">' + list.length + '</span></legend>' +
        '<div class="table-grid">';
      for (var j = 0; j < list.length; j++) {
        var table = list[j];
        html += '<label class="table-option" for="table-' + table.id + '">' +
          '<input type="radio" class="sr-only" id="table-' + table.id +
          '" name="table-choice" value="' + table.id + '">' +
          '<span class="tt-code">' + esc(table.code) + '</span>' +
          '<span class="tt-meta">' + table.seats + ' seats</span>' +
          '<span class="tt-check" aria-hidden="true">' + icon('check') + '</span>' +
          '</label>';
      }
      html += '</div></fieldset>';
    }
    return html;
  }

  function updateTableSelection() {
    var labels = el.resultsTables.querySelectorAll('.table-option');
    for (var i = 0; i < labels.length; i++) {
      var input = labels[i].querySelector('input');
      labels[i].classList.toggle('is-selected', !!(input && input.checked));
    }
  }

  function updateContinueLabel() {
    var table = findResultTable(state.selectedTableId);
    el.tableContinue.textContent = table ? 'Continue with ' + table.code : 'Continue to booking';
  }

  function onTableChange(event) {
    var input = event.target;
    if (!input || input.name !== 'table-choice') {
      return;
    }
    state.selectedTableId = parseInt(input.value, 10);
    updateTableSelection();
    el.tableContinue.disabled = false;
    updateContinueLabel();
  }

  function findResultTable(id) {
    if (!state.result || id === null || id === undefined) {
      return null;
    }
    for (var i = 0; i < state.result.tables.length; i++) {
      if (state.result.tables[i].id === id) {
        return state.result.tables[i];
      }
    }
    return null;
  }

  function nextAvailableSlot() {
    var slots = state.result.slots;
    var current = E.minutes(state.slotTime);
    var after = [];
    var any = [];
    for (var i = 0; i < slots.length; i++) {
      if (E.isPastStart(state.query.date, slots[i].start) || slots[i].available_tables === 0) {
        continue;
      }
      if (E.minutes(slots[i].start) > current) {
        after.push(slots[i].start);
      }
      any.push(slots[i].start);
    }
    return after.length > 0 ? after[0] : (any.length > 0 ? any[0] : null);
  }

  function renderEmptyState() {
    var query = state.query;
    var time = state.slotTime;
    var maxSeats = E.largestTableSeats();
    var title;
    var message;
    var waitlistClass = 'btn btn-secondary';
    var actions = [];

    if (query.partySize > maxSeats) {
      title = 'No single table for ' + guests(query.partySize);
      message = 'Our largest table seats ' + maxSeats + ' guests. For ' + guests(query.partySize) +
        ', contact the restaurant and we will arrange a combined table — or join the waitlist and we will contact you if a suitable table frees up.';
      waitlistClass = 'btn btn-primary';
    } else if (query.area) {
      title = 'No ' + areaLabel(query.area).toLowerCase() + ' tables at ' + time;
      message = 'All ' + areaLabel(query.area).toLowerCase() + ' tables for ' + guests(query.partySize) +
        ' are booked at ' + time + ' on ' + dateLong(query.date) +
        '. Try another start time or search every seating area.';
      actions.push('<button type="button" class="btn btn-primary" data-action="clear-area">Search all areas</button>');
    } else {
      title = 'Fully booked at ' + time;
      message = 'All tables for ' + guests(query.partySize) + ' are taken at ' + time + ' on ' +
        dateLong(query.date) + '. Pick another start time above, or join the waitlist and ' +
        'we will contact you if a table is released.';
      var next = nextAvailableSlot();
      if (next) {
        actions.push('<button type="button" class="btn btn-primary" data-action="select-slot" data-time="' +
          esc(next) + '">Try ' + esc(next) + '</button>');
      } else {
        waitlistClass = 'btn btn-primary';
      }
    }

    actions.push('<button type="button" class="' + waitlistClass + '" data-action="waitlist">Join the waitlist</button>');
    el.resultsEmptyTitle.textContent = title;
    el.resultsEmptyMsg.textContent = message;
    el.resultsEmptyActions.innerHTML = actions.join('');
  }

  function selectSlot(time) {
    try {
      state.result = E.availability(state.query.date, time, state.query.partySize, state.query.area);
    } catch (error) {
      toast(error.message);
      return;
    }
    state.slotTime = time;
    state.selectedTableId = null;
    updateSelectedSlotButtons();
    renderSummary();
    renderTables();
    hideWaitlist();
    announce(availabilityAnnouncement(state.result, time, state.query.partySize));
  }

  function onSlotClick(event) {
    var button = event.target.closest ? event.target.closest('button[data-time]') : null;
    if (!button || button.disabled) {
      return;
    }
    var time = button.getAttribute('data-time');
    if (time === state.slotTime) {
      return;
    }
    selectSlot(time);
  }

  function onEmptyAction(event) {
    var button = event.target.closest ? event.target.closest('button[data-action]') : null;
    if (!button) {
      return;
    }
    var action = button.getAttribute('data-action');
    if (action === 'waitlist') {
      openWaitlist();
    } else if (action === 'clear-area') {
      state.query.area = null;
      el.searchArea.value = '';
      selectSlot(state.slotTime);
    } else if (action === 'select-slot') {
      selectSlot(button.getAttribute('data-time'));
    }
  }

  function onContinue() {
    if (state.selectedTableId === null) {
      return;
    }
    if (!findResultTable(state.selectedTableId)) {
      toast('That table is no longer available. Please choose another one.');
      return;
    }
    renderFormSummary();
    fillForm();
    clearFormErrors();
    showScreen('form');
  }

  /* ================================================================== */
  /* Waitlist                                                            */
  /* ================================================================== */

  function openWaitlist() {
    var query = state.query;
    if (!query) {
      return;
    }
    el.waitlistContext.textContent = 'We will take your details for ' + guests(query.partySize) +
      ' at ' + state.slotTime + ' on ' + dateLong(query.date) +
      (query.area ? ' (' + areaLabel(query.area) + ')' : '') +
      '. The restaurant will contact you if a table frees up.';
    el.waitlistError.hidden = true;
    el.waitlistError.textContent = '';
    el.waitlistSuccess.hidden = true;
    el.waitlistSuccess.textContent = '';
    el.waitlistForm.hidden = false;
    el.waitlistName.value = state.formValues && state.formValues.name ? state.formValues.name : '';
    el.waitlistContact.value = state.formValues && state.formValues.email ? state.formValues.email : '';
    el.waitlistName.removeAttribute('aria-invalid');
    el.waitlistContact.removeAttribute('aria-invalid');
    el.waitlistPanel.hidden = false;
    el.waitlistName.focus();
  }

  function hideWaitlist() {
    el.waitlistPanel.hidden = true;
    el.waitlistForm.hidden = false;
    el.waitlistError.hidden = true;
    el.waitlistSuccess.hidden = true;
  }

  function onWaitlistSubmit(event) {
    event.preventDefault();
    el.waitlistError.hidden = true;
    el.waitlistName.removeAttribute('aria-invalid');
    el.waitlistContact.removeAttribute('aria-invalid');

    try {
      var entry = E.joinWaitlist({
        restaurant_id: data.restaurant.id,
        date: state.query.date,
        time: state.slotTime,
        party_size: state.query.partySize,
        area: state.query.area,
        name: el.waitlistName.value,
        contact: el.waitlistContact.value
      });
      el.waitlistForm.hidden = true;
      el.waitlistSuccess.textContent = 'You are on the waitlist for ' + guests(entry.party_size) +
        ' at ' + entry.start + ' on ' + dateShort(entry.date) +
        '. Waitlist reference #' + entry.id + ' — the restaurant will contact ' +
        entry.guest_contact + ' if a table frees up.';
      el.waitlistSuccess.hidden = false;
      renderActivity();
      toast('Added to the waitlist.');
      announce('Waitlist entry created.');
    } catch (error) {
      if (error && error.code === 'VALIDATION_ERROR') {
        if (error.details && error.details.name) {
          el.waitlistName.setAttribute('aria-invalid', 'true');
        }
        if (error.details && error.details.contact) {
          el.waitlistContact.setAttribute('aria-invalid', 'true');
        }
        el.waitlistError.textContent = firstError(error.details);
      } else {
        el.waitlistError.textContent = errorText(error);
      }
      el.waitlistError.hidden = false;
    }
  }

  /* ================================================================== */
  /* Booking form                                                        */
  /* ================================================================== */

  function renderFormSummary() {
    var query = state.query;
    var table = findResultTable(state.selectedTableId);
    if (!query || !table) {
      return;
    }
    var end = E.addMinutes(state.slotTime, state.result.service_period.slot_minutes);
    el.formSummary.innerHTML = '<dl>' +
      '<div><dt>Date</dt><dd>' + esc(dateLong(query.date)) + '</dd></div>' +
      '<div><dt>Time</dt><dd>' + esc(state.slotTime) + ' – ' + esc(end) + '</dd></div>' +
      '<div><dt>Guests</dt><dd>' + esc(guests(query.partySize)) + '</dd></div>' +
      '<div><dt>Table</dt><dd>' + esc(table.code) + ' · ' + table.seats + ' seats · ' +
      esc(areaLabel(table.area)) + '</dd></div>' +
      '</dl>';
  }

  function fillForm() {
    var values = state.formValues || {};
    el.bkName.value = values.name || '';
    el.bkEmail.value = values.email || '';
    el.bkMobile.value = values.mobile || '';
    el.bkRequests.value = values.special_requests || '';
    el.bkPolicy.checked = !!values.policy;
    updateRequestsCounter();
  }

  function updateRequestsCounter() {
    var length = el.bkRequests.value.length;
    el.requestsCount.textContent = length + ' / ' + data.policy.maxSpecialRequestsLength;
    el.requestsCount.classList.toggle('is-near', length >= 180);
  }

  function clearFormErrors() {
    el.formErrors.hidden = true;
    el.formErrors.innerHTML = '';
    var keys = Object.keys(FIELD_ERRORS);
    for (var i = 0; i < keys.length; i++) {
      var mapping = FIELD_ERRORS[keys[i]];
      var input = $(mapping.input);
      var errorEl = $(mapping.error);
      if (input) {
        input.removeAttribute('aria-invalid');
      }
      if (errorEl) {
        errorEl.hidden = true;
        errorEl.textContent = '';
      }
    }
  }

  function renderFormErrors(errors) {
    clearFormErrors();
    var keys = Object.keys(errors || {});
    if (keys.length === 0) {
      return;
    }
    var listHtml = '';
    for (var i = 0; i < keys.length; i++) {
      var key = keys[i];
      var message = errors[key];
      var mapping = FIELD_ERRORS[key];
      if (mapping) {
        var input = $(mapping.input);
        var errorEl = $(mapping.error);
        if (input) {
          input.setAttribute('aria-invalid', 'true');
        }
        if (errorEl) {
          errorEl.textContent = message;
          errorEl.hidden = false;
        }
        listHtml += '<li><a href="#' + mapping.input + '">' + esc(message) + '</a></li>';
      } else {
        listHtml += '<li>' + esc(message) + '</li>';
      }
    }
    el.formErrors.innerHTML = '<p class="alert-title">Please check the following:</p><ul>' + listHtml + '</ul>';
    el.formErrors.hidden = false;
    el.formErrors.focus();
    announce('The booking form has ' + plural(keys.length, 'error', 'errors') + '.');
  }

  function renderBookingError(error) {
    clearFormErrors();
    var html = '<p class="alert-title">Booking could not be completed</p><p>' +
      esc(error.message || 'Please try again.') + '</p>' +
      '<p class="micro" style="margin-top:6px">Error code: <code>' + esc(error.code || 'ERROR') + '</code></p>';

    if (error.code === 'TABLE_TAKEN' && error.details && error.details.alternatives &&
        error.details.alternatives.length > 0) {
      html += '<p style="margin-top:10px">Available alternatives at the same time:</p><div class="actions">';
      for (var i = 0; i < error.details.alternatives.length; i++) {
        var alt = error.details.alternatives[i];
        html += '<button type="button" class="btn btn-small btn-secondary" data-alternative="' +
          alt.id + '">' + esc(alt.code) + ' · ' + alt.seats + ' seats · ' + esc(areaLabel(alt.area)) +
          '</button>';
      }
      html += '</div>';
    }

    el.formErrors.innerHTML = html;
    el.formErrors.hidden = false;
    el.formErrors.focus();
  }

  function onBookingSubmit(event) {
    event.preventDefault();
    if (!state.query || !state.result) {
      toast('Your search expired. Please start again.');
      resetFlow();
      return;
    }

    var input = {
      restaurant_id: data.restaurant.id,
      table_id: state.selectedTableId,
      date: state.query.date,
      time: state.slotTime,
      party_size: state.query.partySize,
      name: el.bkName.value,
      email: el.bkEmail.value,
      mobile: el.bkMobile.value,
      special_requests: el.bkRequests.value
    };

    state.formValues = {
      name: input.name,
      email: input.email,
      mobile: input.mobile,
      special_requests: input.special_requests,
      policy: el.bkPolicy.checked
    };

    // Aggregate every field error before writing anything (mirrors the PHP
    // engine's single VALIDATION_ERROR response). The policy checkbox is a
    // UI-only requirement and is checked here.
    var errors = E.validateBookingInput(input);
    if (!el.bkPolicy.checked) {
      errors.policy = 'Please accept the cancellation policy to continue.';
    }
    if (Object.keys(errors).length > 0) {
      renderFormErrors(errors);
      return;
    }

    try {
      var outcome = E.createBooking(input);
      stopTimer();
      state.lastBooking = outcome.booking;
      state.lastElapsed = elapsedSeconds();
      renderConfirm(outcome.booking);
      renderActivity();
      showScreen('confirm');
      announce('Booking confirmed. Reference ' +
        outcome.booking.reference.split('').join(' ') + '. Table ' +
        outcome.booking.table.code + ' at ' + outcome.booking.start + '.');
    } catch (error) {
      if (error && error.code === 'VALIDATION_ERROR') {
        renderFormErrors(error.details || {});
      } else {
        renderBookingError(error);
      }
    }
  }

  function onFormErrorClick(event) {
    var button = event.target.closest ? event.target.closest('button[data-alternative]') : null;
    if (!button) {
      return;
    }
    state.selectedTableId = parseInt(button.getAttribute('data-alternative'), 10);
    backToResults();
  }

  function backToResults() {
    if (!state.query) {
      showScreen('search');
      return;
    }
    try {
      state.result = E.availability(
        state.query.date,
        state.slotTime || state.query.time,
        state.query.partySize,
        state.query.area
      );
    } catch (error) {
      showSearchError(errorText(error));
      showScreen('search');
      return;
    }
    renderResults();
    showScreen('results');
  }

  /* ================================================================== */
  /* Confirmation                                                        */
  /* ================================================================== */

  function detailRow(label, valueHtml) {
    return '<div class="detail-row"><dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
  }

  function renderConfirm(booking) {
    el.confirmReference.textContent = booking.reference;
    el.confirmLead.textContent = 'Table ' + booking.table.code + ' is held for ' +
      guests(booking.party_size) + ' on ' + dateLong(booking.date) + ' at ' + booking.start + '.';

    var rows = '';
    rows += detailRow('Date', esc(dateLong(booking.date)));
    rows += detailRow('Time', esc(booking.start) + ' – ' + esc(booking.end) + ' (' +
      (E.minutes(booking.end) - E.minutes(booking.start)) + ' min)');
    rows += detailRow('Table', esc(booking.table.code) + ' · ' + booking.table.seats +
      ' seats · ' + esc(areaLabel(booking.table.area)));
    rows += detailRow('Party', esc(guests(booking.party_size)));
    rows += detailRow('Guest', esc(booking.guest.name) + '<span class="soft">' +
      esc(booking.guest.email) + (booking.guest.mobile ? ' · ' + esc(booking.guest.mobile) : '') +
      '</span>');
    if (booking.special_requests) {
      rows += detailRow('Requests', esc(booking.special_requests));
    }
    el.confirmBody.innerHTML = rows;

    var cutoff = parseISO(booking.cutoff_at);
    var cutoffPassed = Date.parse(booking.cutoff_at) <= Date.now();
    var cutoffHtml = '<div class="cutoff-box' + (cutoffPassed ? ' is-passed' : '') + '">' +
      icon(cutoffPassed ? 'warning' : 'clock') +
      '<div><strong>Free cancellation until ' +
      (cutoff ? esc(dateShort(cutoff.date)) + ' at ' + esc(cutoff.time) : esc(booking.cutoff_at)) +
      (cutoffPassed ? ' (cut-off has passed)' : '') +
      '</strong><p>' + (cutoffPassed
        ? 'The cut-off for this booking has already passed — contact the restaurant for changes.'
        : 'Cancel before this time free of charge; after it the booking is chargeable.') +
      '</p></div></div>';

    var reminders = Array.isArray(booking.reminders) ? booking.reminders : [];
    var reminderHtml = '<div class="reminders"><h3>Reminders</h3><ul>' +
      '<li>' + icon('bell') + '<span class="when">' +
      esc(dateTimeFromISO(reminders[0] || '')) + '</span><span class="hint">24 h before</span></li>' +
      '<li>' + icon('bell') + '<span class="when">' +
      esc(dateTimeFromISO(reminders[1] || '')) + '</span><span class="hint">2 h before</span></li>' +
      '</ul><p class="micro">The full system sends these by e-mail / SMS; the demo only shows the scheduled times.</p></div>';

    el.confirmExtra.innerHTML = cutoffHtml + reminderHtml;

    el.copyFeedback.textContent = '';
    renderTimerResult();
  }

  function renderTimerResult() {
    var seconds = state.lastElapsed;
    if (seconds === null) {
      el.confirmTimer.hidden = true;
      return;
    }
    var over = seconds > 60;
    el.confirmTimer.hidden = false;
    el.confirmTimer.className = 'timer-result' + (over ? ' is-over' : '');
    el.confirmTimer.innerHTML = icon(over ? 'warning' : 'check') +
      '<div><span>You completed this booking in ' + seconds + ' ' +
      (seconds === 1 ? 'second' : 'seconds') + '.</span><span class="sub">' +
      (over
        ? 'The target for one booking is under 60 seconds (project success metric).'
        : 'That is inside the 60-second booking target from the project success metrics.') +
      '</span></div>';
  }

  function legacyCopy(text) {
    try {
      var field = document.createElement('textarea');
      field.value = text;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.top = '-1000px';
      document.body.appendChild(field);
      field.select();
      var copied = document.execCommand ? document.execCommand('copy') : false;
      document.body.removeChild(field);
      return !!copied;
    } catch (error) {
      return false;
    }
  }

  function copyReference() {
    if (!state.lastBooking) {
      return;
    }
    var reference = state.lastBooking.reference;

    function finish(ok) {
      el.copyFeedback.textContent = ok
        ? 'Copied to clipboard.'
        : 'Press Ctrl/Cmd + C to copy the reference.';
      if (ok) {
        toast('Reference copied: ' + reference);
      }
    }

    if (global.navigator && global.navigator.clipboard && global.navigator.clipboard.writeText) {
      global.navigator.clipboard.writeText(reference).then(function () {
        finish(true);
      }, function () {
        finish(legacyCopy(reference));
      });
    } else {
      finish(legacyCopy(reference));
    }
  }

  function icsEscape(text) {
    return String(text)
      .replace(/\\/g, '\\\\')
      .replace(/;/g, '\\;')
      .replace(/,/g, '\\,')
      .replace(/\r?\n/g, '\\n');
  }

  function addToCalendar() {
    var booking = state.lastBooking;
    if (!booking) {
      return;
    }
    var dtStart = booking.date.replace(/-/g, '') + 'T' + booking.start.replace(':', '') + '00';
    var dtEnd = booking.date.replace(/-/g, '') + 'T' + booking.end.replace(':', '') + '00';
    var stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
    var description = 'DineReserve demo booking ' + booking.reference +
      ' · Party of ' + booking.party_size +
      ' · Table ' + booking.table.code +
      ' · Free cancellation until ' + booking.cutoff_at +
      '. Reference: ' + booking.reference;
    var lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//DineReserve//Demo//EN',
      'CALSCALE:GREGORIAN',
      'BEGIN:VEVENT',
      'UID:' + booking.reference + '@dinereserve.demo',
      'DTSTAMP:' + stamp,
      'DTSTART;TZID=Asia/Macau:' + dtStart,
      'DTEND;TZID=Asia/Macau:' + dtEnd,
      'SUMMARY:' + icsEscape('DineReserve booking ' + booking.reference),
      'LOCATION:' + icsEscape(data.restaurant.address),
      'DESCRIPTION:' + icsEscape(description),
      'END:VEVENT',
      'END:VCALENDAR',
      ''
    ];
    var ics = lines.join('\r\n');

    try {
      var blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
      var url = URL.createObjectURL(blob);
      var link = document.createElement('a');
      link.href = url;
      link.download = 'dinereserve-' + booking.reference + '.ics';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      global.setTimeout(function () {
        URL.revokeObjectURL(url);
      }, 1500);
      toast('Calendar file downloaded.');
    } catch (error) {
      toast('The calendar file could not be created in this browser.');
    }
  }

  /* ================================================================== */
  /* Activity list                                                       */
  /* ================================================================== */

  function renderActivity() {
    var bookings = E.listBookings();
    var waitlist = E.listWaitlist();
    var total = bookings.length + waitlist.length;
    var html = '';

    el.activityCount.textContent = String(total);

    for (var i = 0; i < bookings.length; i++) {
      var booking = bookings[i];
      var cancelled = booking.status === 'CANCELLED';
      var cutoff = parseISO(booking.cutoff_at);
      html += '<li class="activity-item"><div class="activity-main">' +
        '<span class="ref-chip">' + esc(booking.reference) + '</span>' +
        '<span class="activity-title">' + esc(dateShort(booking.date)) + ' · ' +
        esc(booking.start) + ' · ' + esc(guests(booking.party_size)) + '</span>' +
        '<span class="activity-sub">' + esc(booking.table.code) + ' · ' +
        esc(areaLabel(booking.table.area)) + ' · <span class="status ' +
        (cancelled ? 'status-cancelled' : 'status-confirmed') + '">' +
        (cancelled ? 'Cancelled' : 'Confirmed') + '</span>' +
        (cutoff ? ' · cut-off ' + esc(cutoff.time) : '') + '</span>' +
        '</div>';
      if (!cancelled) {
        html += '<button type="button" class="btn btn-small btn-secondary" data-cancel-reference="' +
          esc(booking.reference) + '">Cancel</button>';
      }
      html += '</li>';
    }

    for (var j = 0; j < waitlist.length; j++) {
      var entry = waitlist[j];
      html += '<li class="activity-item"><div class="activity-main">' +
        '<span class="ref-chip">Waitlist #' + esc(entry.id) + '</span>' +
        '<span class="activity-title">' + esc(dateShort(entry.date)) + ' · ' +
        esc(entry.start) + ' · ' + esc(guests(entry.party_size)) + '</span>' +
        '<span class="activity-sub">' + esc(entry.guest_name) + ' · ' +
        esc(entry.guest_contact) + ' · <span class="status status-waiting">Waiting</span></span>' +
        '</div>' +
        '<button type="button" class="btn btn-small btn-secondary" data-remove-waitlist="' +
        entry.id + '">Remove</button></li>';
    }

    el.activityList.innerHTML = html;
    el.activityEmpty.hidden = total > 0;
  }

  function onActivityClick(event) {
    var target = event.target;
    var cancelButton = target.closest ? target.closest('button[data-cancel-reference]') : null;
    if (cancelButton) {
      cancelBooking(cancelButton.getAttribute('data-cancel-reference'));
      return;
    }
    var removeButton = target.closest ? target.closest('button[data-remove-waitlist]') : null;
    if (removeButton) {
      E.removeWaitlist(parseInt(removeButton.getAttribute('data-remove-waitlist'), 10));
      renderActivity();
      toast('Waitlist entry removed.');
      announce('Waitlist entry removed.');
    }
  }

  function cancelBooking(reference) {
    if (!global.confirm('Cancel booking ' + reference + '? This releases the table for other diners.')) {
      return;
    }
    try {
      E.cancelBooking(reference);
      renderActivity();
      toast('Booking ' + reference + ' cancelled — the table is free again.');
      announce('Booking ' + reference.split('').join(' ') + ' cancelled. The table is free again.');
      if (state.screen === 'results') {
        backToResults();
      }
    } catch (error) {
      toast(error.message);
    }
  }

  /* ================================================================== */
  /* Reset / new booking                                                 */
  /* ================================================================== */

  function resetFlow() {
    state.query = null;
    state.result = null;
    state.slotTime = null;
    state.selectedTableId = null;
    state.formValues = null;
    state.lastBooking = null;
    state.lastElapsed = null;

    resetTimer();
    hideWaitlist();
    clearFormErrors();
    hideSearchError();
    el.confirmReference.textContent = '';
    el.confirmLead.textContent = '';
    el.confirmBody.innerHTML = '';
    el.confirmExtra.innerHTML = '';
    el.confirmTimer.hidden = true;
    el.copyFeedback.textContent = '';
    el.searchParty.value = '2';
    el.searchArea.value = '';

    initSearchForm();
    showScreen('search');
  }

  function onResetDemo() {
    var bookings = E.listBookings();
    var waitlist = E.listWaitlist();
    if (bookings.length + waitlist.length > 0) {
      if (!global.confirm('Reset the demo? This deletes all bookings and waitlist entries stored in this browser.')) {
        return;
      }
    }
    E.resetDemoData();
    resetFlow();
    renderActivity();
    toast('Demo data reset.');
    announce('Demo data cleared from this browser.');
  }

  function onBookAnother() {
    resetFlow();
    toast('Ready for a new booking.');
    announce('New booking started. The timer has been reset.');
  }

  /* ================================================================== */
  /* Wiring                                                              */
  /* ================================================================== */

  function cacheElements() {
    el.timerChip = $('timer-chip');
    el.timerValue = $('timer-value');
    el.resetDemo = $('reset-demo');
    el.liveRegion = $('live-region');
    el.toast = $('toast');
    el.venuePeriods = $('venue-periods');

    el.screens = {
      search: $('screen-search'),
      results: $('screen-results'),
      form: $('screen-form'),
      confirm: $('screen-confirm')
    };

    el.searchForm = $('search-form');
    el.searchDate = $('search-date');
    el.searchTime = $('search-time');
    el.searchParty = $('search-party');
    el.searchArea = $('search-area');
    el.searchSubmit = $('search-submit');
    el.searchHint = $('search-hint');
    el.searchError = $('search-error');
    el.partyHint = $('party-hint');

    el.activityList = $('activity-list');
    el.activityCount = $('activity-count');
    el.activityEmpty = $('activity-empty');

    el.resultsBack = $('results-back');
    el.resultsMeta = $('results-meta');
    el.resultsSummary = $('results-summary');
    el.slotPeriodLabel = $('slot-period-label');
    el.resultsSlots = $('results-slots');
    el.resultsTablesCard = $('results-tables-card');
    el.tablesHeading = $('tables-heading');
    el.tablesSub = $('tables-sub');
    el.resultsTables = $('results-tables');
    el.tableContinue = $('table-continue');
    el.resultsEmpty = $('results-empty');
    el.resultsEmptyTitle = $('results-empty-title');
    el.resultsEmptyMsg = $('results-empty-msg');
    el.resultsEmptyActions = $('results-empty-actions');

    el.waitlistPanel = $('waitlist-panel');
    el.waitlistContext = $('waitlist-context');
    el.waitlistForm = $('waitlist-form');
    el.waitlistName = $('waitlist-name');
    el.waitlistContact = $('waitlist-contact');
    el.waitlistError = $('waitlist-error');
    el.waitlistSuccess = $('waitlist-success');
    el.waitlistCancel = $('waitlist-cancel');

    el.formBack = $('form-back');
    el.formSummary = $('form-summary');
    el.bookingForm = $('booking-form');
    el.formErrors = $('form-errors');
    el.bkName = $('bk-name');
    el.bkEmail = $('bk-email');
    el.bkMobile = $('bk-mobile');
    el.bkRequests = $('bk-requests');
    el.requestsCount = $('bk-requests-count');
    el.bkPolicy = $('bk-policy');
    el.bookingSubmit = $('booking-submit');

    el.confirmLead = $('confirm-lead');
    el.confirmReference = $('confirm-reference');
    el.copyReference = $('copy-reference');
    el.copyFeedback = $('copy-feedback');
    el.confirmBody = $('confirm-body');
    el.confirmExtra = $('confirm-extra');
    el.confirmTimer = $('confirm-timer');
    el.addCalendar = $('add-calendar');
    el.bookAnother = $('book-another');
  }

  function bindEvents() {
    el.searchForm.addEventListener('submit', onSearchSubmit);
    el.searchDate.addEventListener('change', function () {
      refreshTimeOptions(null, '');
    });
    el.searchParty.addEventListener('change', updatePartyHint);
    el.resetDemo.addEventListener('click', onResetDemo);

    el.resultsBack.addEventListener('click', function () {
      showScreen('search');
    });
    el.resultsSlots.addEventListener('click', onSlotClick);
    el.resultsTables.addEventListener('change', onTableChange);
    el.tableContinue.addEventListener('click', onContinue);
    el.resultsEmptyActions.addEventListener('click', onEmptyAction);

    el.waitlistForm.addEventListener('submit', onWaitlistSubmit);
    el.waitlistCancel.addEventListener('click', hideWaitlist);

    el.formBack.addEventListener('click', backToResults);
    el.bookingForm.addEventListener('submit', onBookingSubmit);
    el.bkRequests.addEventListener('input', updateRequestsCounter);
    el.formErrors.addEventListener('click', onFormErrorClick);

    el.activityList.addEventListener('click', onActivityClick);
    el.copyReference.addEventListener('click', copyReference);
    el.addCalendar.addEventListener('click', addToCalendar);
    el.bookAnother.addEventListener('click', onBookAnother);
  }

  function init() {
    cacheElements();
    renderVenuePeriods();
    initSearchForm();
    renderActivity();
    resetTimer();
    bindEvents();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(typeof window !== 'undefined' ? window : this);
