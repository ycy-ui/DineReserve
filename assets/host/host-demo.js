/**
 * Host console — offline demo store.
 *
 * GitHub Pages has no PHP runtime, so the merchant console needs a browser-only
 * data layer to stay demonstrable. This module reuses the diner demo's engine
 * (assets/demo/engine.js — same rules as the PHP engine: 15-minute grid, 90-day
 * window, turnaround buffer, half-open overlap, 8-character references) and adds
 * the host-side operations the API offers: floor plan, seat, no-show, release,
 * phone bookings, waitlist and daily reports.
 *
 * Everything persists in localStorage, so the console behaves like the real one
 * until the browser data is cleared.
 */
(function (global) {
  'use strict';

  var NS = global.DineReserveDemo || (global.DineReserveDemo = {});
  var data = NS.data;
  var engine = NS.Engine;
  var policy = data.policy;

  var HOST_KEY = 'dinereserve.host.demo.v1';
  var WAITLIST_KEY = 'dinereserve.host.demo.waitlist.v1';

  function readJSON(key, fallback) {
    try {
      var raw = global.localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) {
      return fallback;
    }
  }

  function writeJSON(key, value) {
    try {
      global.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      /* storage may be unavailable (private mode) — the console still works in memory */
    }
  }

  function readState() {
    var state = readJSON(HOST_KEY, null);
    if (!state || typeof state !== 'object') {
      state = { overrides: {}, nextWaitlistId: 1 };
    }
    state.overrides = state.overrides || {};
    state.nextWaitlistId = state.nextWaitlistId || 1;
    return state;
  }

  function writeState(state) {
    writeJSON(HOST_KEY, state);
  }

  function readBookings() {
    var bookings = readJSON(data.storage.bookingsKey, []);
    return Array.isArray(bookings) ? bookings : [];
  }

  function writeBookings(bookings) {
    writeJSON(data.storage.bookingsKey, bookings);
  }

  function readWaitlist() {
    var list = readJSON(WAITLIST_KEY, []);
    return Array.isArray(list) ? list : [];
  }

  function nowISO() {
    return new Date().toISOString();
  }

  function tableOf(id) {
    for (var i = 0; i < data.tables.length; i++) {
      if (data.tables[i].id === Number(id)) return data.tables[i];
    }
    return null;
  }

  function minutesOf(hhmm) {
    var parts = String(hhmm).split(':');
    return Number(parts[0]) * 60 + Number(parts[1]);
  }

  function capacityPerDay() {
    var seats = 0;
    for (var i = 0; i < data.tables.length; i++) {
      var status = String(data.tables[i].status || 'FREE').toUpperCase();
      if (status !== 'BLOCKED' && status !== 'MAINTENANCE') seats += Number(data.tables[i].seats);
    }
    var turns = 0;
    for (var p = 0; p < data.servicePeriods.length; p++) {
      var period = data.servicePeriods[p];
      var length = minutesOf(period.end) - minutesOf(period.start);
      var sitting = Number(period.avgDiningMinutes) + policy.turnoverBufferMinutes;
      turns += Math.max(1, Math.floor(Math.max(0, length - Number(period.avgDiningMinutes)) / sitting) + 1);
    }
    return seats * (turns || 1);
  }

  function bookingsOn(date) {
    return readBookings().filter(function (booking) {
      return String(booking.date) === String(date);
    });
  }

  function overrideKey(date, tableId) {
    return String(date) + ':' + String(tableId);
  }

  function bookingForTable(floorBookings, tableId) {
    var current = null;
    var upcoming = null;
    for (var i = 0; i < floorBookings.length; i++) {
      var booking = floorBookings[i];
      if (Number(booking.table && booking.table.id) !== Number(tableId)) continue;
      var status = String(booking.status || '').toUpperCase();
      if (status !== 'CONFIRMED' && status !== 'SEATED') continue;
      if (status === 'SEATED') {
        current = current || booking;
        continue;
      }
      if (!upcoming) upcoming = booking;
    }
    return { current: current, upcoming: upcoming };
  }

  function floorPlan(date, time) {
    var state = readState();
    var dayBookings = bookingsOn(date).filter(function (booking) {
      var status = String(booking.status || '').toUpperCase();
      return status === 'CONFIRMED' || status === 'SEATED';
    });
    var summary = { free: 0, occupied: 0, cleaning: 0, blocked: 0, maintenance: 0, booked_today: dayBookings.length };
    var tables = [];
    for (var i = 0; i < data.tables.length; i++) {
      var table = data.tables[i];
      var status = String(table.status || 'FREE').toUpperCase();
      var override = state.overrides[overrideKey(date, table.id)];
      if (override) status = override;
      var pair = bookingForTable(dayBookings, table.id);
      if (pair.current && status === 'FREE') status = 'OCCUPIED';
      if (!override && pair.current) status = 'OCCUPIED';
      if (status === 'OCCUPIED') summary.occupied++;
      else if (status === 'CLEANING') summary.cleaning++;
      else if (status === 'BLOCKED') summary.blocked++;
      else if (status === 'MAINTENANCE') summary.maintenance++;
      else summary.free++;
      tables.push({
        id: table.id,
        code: table.code,
        seats: table.seats,
        area: table.area,
        position: { x: table.posX, y: table.posY },
        status: status,
        seated: !!(pair.current && String(pair.current.status).toUpperCase() === 'SEATED'),
        current_booking: pair.current,
        upcoming_booking: pair.upcoming,
      });
    }
    return {
      restaurant: { id: 1, name: 'DineReserve Demo Restaurant', timezone: policy.timezone },
      date: date,
      time: time,
      poll_after_seconds: 0,
      summary: summary,
      tables: tables,
    };
  }

  function fail(code, message, status, details) {
    var error = new Error(message);
    error.code = code;
    error.status = status || 422;
    error.details = details || {};
    throw error;
  }

  function setBookingStatus(reference, status) {
    var bookings = readBookings();
    for (var i = 0; i < bookings.length; i++) {
      if (String(bookings[i].reference) === String(reference)) {
        bookings[i] = Object.assign({}, bookings[i], { status: status, updated_at: nowISO() });
        writeBookings(bookings);
        return bookings[i];
      }
    }
    fail('NOT_FOUND', 'Booking not found.', 404);
    return null;
  }

  function findBooking(reference) {
    var bookings = readBookings();
    for (var i = 0; i < bookings.length; i++) {
      if (String(bookings[i].reference) === String(reference)) return bookings[i];
    }
    return null;
  }

  function setOverride(date, tableId, status) {
    var state = readState();
    state.overrides[overrideKey(date, tableId)] = status;
    writeState(state);
  }

  function seat(reference) {
    var booking = findBooking(reference);
    if (!booking) fail('NOT_FOUND', 'Booking not found.', 404);
    if (String(booking.status).toUpperCase() === 'SEATED') {
      fail('ALREADY_SEATED', 'This booking is already seated.', 409);
    }
    if (String(booking.status).toUpperCase() !== 'CONFIRMED') {
      fail('BOOKING_NOT_SEATABLE', 'Only a confirmed booking can be seated.', 409, { status: booking.status });
    }
    var seated = setBookingStatus(reference, 'SEATED');
    setOverride(booking.date, booking.table.id, 'OCCUPIED');
    return { booking: seated };
  }

  function noShow(reference) {
    var booking = findBooking(reference);
    if (!booking) fail('NOT_FOUND', 'Booking not found.', 404);
    if (String(booking.status).toUpperCase() !== 'CONFIRMED') {
      fail('BOOKING_NOT_NO_SHOW', 'Only a confirmed booking can be marked as a no-show.', 409, { status: booking.status });
    }
    var marked = setBookingStatus(reference, 'NO_SHOW');
    setOverride(booking.date, booking.table.id, 'CLEANING');
    return { booking: marked, table: { id: booking.table.id, status: 'CLEANING' } };
  }

  function release(tableId, date) {
    var day = date || new Date().toISOString().slice(0, 10);
    var bookings = readBookings();
    var completed = false;
    for (var i = 0; i < bookings.length; i++) {
      if (String(bookings[i].date) === day
        && Number(bookings[i].table && bookings[i].table.id) === Number(tableId)
        && String(bookings[i].status).toUpperCase() === 'SEATED') {
        bookings[i] = Object.assign({}, bookings[i], { status: 'COMPLETED', updated_at: nowISO() });
        completed = true;
      }
    }
    if (completed) writeBookings(bookings);
    setOverride(day, tableId, 'FREE');
    return { table: { id: Number(tableId), status: 'FREE' }, completed: completed };
  }

  function availability(date, time, party, area) {
    var result = engine.availability(date, time, Number(party), area || null);
    return {
      tables: result.tables,
      summary: result.summary,
      slots: result.slots,
    };
  }

  function createBooking(input) {
    // The demo engine returns { booking, created } (same envelope as the API).
    var result = engine.createBooking({
      date: input.date,
      time: input.time,
      party_size: Number(input.party_size),
      table_id: Number(input.table_id),
      name: input.name,
      email: input.email,
      mobile: input.mobile || '',
      special_requests: input.special_requests || '',
    });
    var booking = result && result.booking ? result.booking : result;
    var channel = input.channel || 'phone';
    var bookings = readBookings();
    for (var i = 0; i < bookings.length; i++) {
      if (String(bookings[i].reference) === String(booking.reference)) {
        bookings[i].channel = channel;
      }
    }
    writeBookings(bookings);

    booking.channel = channel;

    return { booking: booking, created: true };
  }

  function waitlistJoin(input) {
    var list = readWaitlist();
    var state = readState();
    var entry = {
      id: state.nextWaitlistId,
      date: input.date,
      time: input.time,
      party_size: Number(input.party_size),
      name: input.name,
      email: input.email,
      status: 'WAITING',
      created_at: nowISO(),
    };
    var position = list.filter(function (item) {
      return item.status === 'WAITING' && item.date === entry.date;
    }).length + 1;
    list.push(entry);
    state.nextWaitlistId += 1;
    writeJSON(WAITLIST_KEY, list);
    writeState(state);

    return { entry: entry, position: position, created: true };
  }

  function occupancy(from, to) {
    var capacity = capacityPerDay();
    var days = [];
    var totals = {
      covers: 0, capacity: 0, bookings: 0, no_shows: 0, cancellations: 0,
      utilisation_pct: 0, no_show_rate_pct: 0,
      channels: { online: 0, phone: 0, walk_in: 0, waitlist: 0 },
    };
    var cursor = new Date(from + 'T00:00:00Z');
    var end = new Date(to + 'T00:00:00Z');
    var maxDays = 92;
    var guard = 0;
    while (cursor <= end && guard <= maxDays) {
      var iso = cursor.toISOString().slice(0, 10);
      var dayBookings = bookingsOn(iso);
      var day = {
        date: iso, covers: 0, capacity: capacity, utilisation_pct: 0, bookings: 0,
        no_shows: 0, cancellations: 0, no_show_rate_pct: 0,
        channels: { online: 0, phone: 0, walk_in: 0, waitlist: 0 },
      };
      for (var i = 0; i < dayBookings.length; i++) {
        var booking = dayBookings[i];
        var status = String(booking.status || '').toUpperCase();
        var channel = String(booking.channel || 'online');
        if (day.channels[channel] !== undefined) day.channels[channel] += 1;
        day.bookings += 1;
        if (status === 'CONFIRMED' || status === 'SEATED' || status === 'COMPLETED') {
          day.covers += Number(booking.party_size || 0);
        } else if (status === 'NO_SHOW') day.no_shows += 1;
        else if (status === 'CANCELLED') day.cancellations += 1;
      }
      day.utilisation_pct = capacity > 0 ? Math.round((day.covers / capacity) * 1000) / 10 : 0;
      var servedOrMissed = day.bookings - day.cancellations;
      day.no_show_rate_pct = servedOrMissed > 0 ? Math.round((day.no_shows / servedOrMissed) * 1000) / 10 : 0;

      totals.covers += day.covers;
      totals.capacity += day.capacity;
      totals.bookings += day.bookings;
      totals.no_shows += day.no_shows;
      totals.cancellations += day.cancellations;
      for (var key in day.channels) totals.channels[key] += day.channels[key];
      days.push(day);
      cursor.setUTCDate(cursor.getUTCDate() + 1);
      guard += 1;
    }
    totals.utilisation_pct = totals.capacity > 0 ? Math.round((totals.covers / totals.capacity) * 1000) / 10 : 0;
    var denom = totals.bookings - totals.cancellations;
    totals.no_show_rate_pct = denom > 0 ? Math.round((totals.no_shows / denom) * 1000) / 10 : 0;

    return {
      kind: 'occupancy',
      restaurant: { id: 1, name: 'DineReserve Demo Restaurant', timezone: policy.timezone },
      from: from,
      to: to,
      generated_at: nowISO(),
      capacity_per_day: capacity,
      days: days,
      totals: totals,
    };
  }

  var BANDS = { high: 70, medium: 40 };

  function scoreBooking(booking) {
    var score = 10;
    var reasons = ['baseline'];
    var start = new Date(String(booking.created_at || '').slice(0, 10) + 'T' + booking.start + ':00+08:00');
    var created = new Date(booking.created_at || nowISO());
    var leadHours = (start.getTime() - created.getTime()) / 3600000;
    if (leadHours < 24) { score += 15; reasons.push('short_lead'); }
    else if (leadHours < 72) { score += 10; reasons.push('medium_lead'); }
    else if (leadHours < 168) { score += 5; reasons.push('week_lead'); }

    var weekday = new Date(booking.date + 'T12:00:00+08:00').getUTCDay();
    if (weekday === 5 || weekday === 6) { score += 10; reasons.push('peak_weekday'); }
    else if (weekday === 0) { score += 5; reasons.push('weekend'); }

    if (minutesOf(booking.start) >= 20 * 60) { score += 5; reasons.push('late_sitting'); }
    var party = Number(booking.party_size || 0);
    if (party >= 8) { score += 12; reasons.push('large_party'); }
    else if (party >= 6) { score += 8; reasons.push('group'); }

    var channel = String(booking.channel || 'online');
    if (channel === 'online') { score += 5; reasons.push('channel_online'); }
    else if (channel === 'phone') { score -= 5; reasons.push('channel_phone'); }
    else if (channel === 'walk_in') { score -= 10; reasons.push('channel_walk_in'); }
    else if (channel === 'waitlist') { score -= 5; reasons.push('channel_waitlist'); }

    if (String(booking.special_requests || '') !== '') { score -= 3; reasons.push('special_request'); }
    score = Math.max(0, Math.min(100, score));
    var band = score >= BANDS.high ? 'HIGH' : (score >= BANDS.medium ? 'MEDIUM' : 'LOW');
    return { score: score, band: band, reasons: reasons, rule_version: 'risk-v1' };
  }

  function risk(from, to) {
    var distribution = { HIGH: 0, MEDIUM: 0, LOW: 0 };
    var flagged = [];
    var sum = 0;
    var scored = 0;
    var bookings = readBookings().filter(function (booking) {
      return String(booking.date) >= from && String(booking.date) <= to;
    });
    for (var i = 0; i < bookings.length; i++) {
      var result = scoreBooking(bookings[i]);
      distribution[result.band] += 1;
      sum += result.score;
      scored += 1;
      if (result.band === 'HIGH') {
        flagged.push({
          reference: bookings[i].reference,
          date: bookings[i].date,
          time: bookings[i].start,
          party_size: Number(bookings[i].party_size || 0),
          channel: bookings[i].channel || 'online',
          status: bookings[i].status,
          score: result.score,
          rule_version: result.rule_version,
        });
      }
    }
    return {
      kind: 'risk',
      restaurant: { id: 1, name: 'DineReserve Demo Restaurant' },
      from: from,
      to: to,
      generated_at: nowISO(),
      scored_bookings: scored,
      average_score: scored ? Math.round((sum / scored) * 10) / 10 : 0,
      distribution: distribution,
      rule_versions: ['risk-v1'],
      flagged: flagged,
    };
  }

  function reset() {
    writeJSON(HOST_KEY, { overrides: {}, nextWaitlistId: 1 });
    writeJSON(WAITLIST_KEY, []);
    if (engine.resetDemoData) engine.resetDemoData();
  }

  NS.HostDemo = {
    floorPlan: floorPlan,
    seat: seat,
    noShow: noShow,
    release: release,
    availability: availability,
    createBooking: createBooking,
    waitlistJoin: waitlistJoin,
    occupancy: occupancy,
    risk: risk,
    reset: reset,
    capacityPerDay: capacityPerDay,
  };
})(typeof window !== 'undefined' ? window : this);
