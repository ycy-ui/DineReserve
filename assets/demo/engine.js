/*
 * DineReserve demo — booking rules engine.
 *
 * A browser-side mirror of the PHP engine (docs/02-architecture.md §5–§6):
 *   - service periods, 15-minute start times, turnover buffer
 *   - half-open interval conflict detection (`aStart < bEnd && bStart < aEnd`)
 *   - table capacity / area / status filtering
 *   - booking validation, 8-character reference, cut-off and reminders
 *
 * Bookings are persisted in localStorage so a refresh keeps the demo state.
 * Plain script, exposes window.DineReserveDemo.Engine.
 */
(function (global) {
  'use strict';

  var NS = global.DineReserveDemo || (global.DineReserveDemo = {});
  var data = NS.data;
  if (!data) {
    throw new Error('assets/demo/data.js must be loaded before engine.js');
  }

  var policy = data.policy;
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/;

  /* ================================================================== */
  /* Errors — mirrors DomainException(code, message, details, status)    */
  /* ================================================================== */

  function DemoError(code, message, details, status) {
    this.name = 'DemoError';
    this.code = code;
    this.message = message;
    this.details = details || {};
    this.status = status || 422;
  }
  DemoError.prototype = Object.create(Error.prototype);
  DemoError.prototype.constructor = DemoError;

  function validationError(fields) {
    return new DemoError(
      'VALIDATION_ERROR',
      'Please correct the highlighted fields.',
      fields,
      422
    );
  }

  /* ================================================================== */
  /* Time helpers — mirror src/Slot.php                                  */
  /* ================================================================== */

  function pad2(value) {
    return value < 10 ? '0' + value : String(value);
  }

  function minutes(hhmm) {
    var match = /^(\d{2}):(\d{2})$/.exec(String(hhmm));
    if (!match) {
      throw new DemoError('VALIDATION_ERROR', 'Invalid time: ' + hhmm, { time: 'Invalid time.' }, 422);
    }
    var hours = parseInt(match[1], 10);
    var mins = parseInt(match[2], 10);
    if (hours > 24 || mins > 59) {
      throw new DemoError('VALIDATION_ERROR', 'Invalid time: ' + hhmm, { time: 'Invalid time.' }, 422);
    }
    return hours * 60 + mins;
  }

  function formatTime(totalMinutes) {
    var safe = Math.max(0, Math.round(totalMinutes));
    var hours = Math.floor(safe / 60);
    var mins = safe % 60;
    return pad2(hours) + ':' + pad2(mins);
  }

  function addMinutes(hhmm, delta) {
    return formatTime(minutes(hhmm) + delta);
  }

  function isValidTime(value) {
    try {
      minutes(value);
      return true;
    } catch (error) {
      return false;
    }
  }

  function isValidDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return false;
    }
    var year = parseInt(value.slice(0, 4), 10);
    var month = parseInt(value.slice(5, 7), 10);
    var day = parseInt(value.slice(8, 10), 10);
    if (month < 1 || month > 12 || day < 1 || day > 31) {
      return false;
    }
    var probe = new Date(Date.UTC(year, month - 1, day));
    return probe.getUTCFullYear() === year &&
      probe.getUTCMonth() === month - 1 &&
      probe.getUTCDate() === day;
  }

  /** Half-open overlap: back-to-back bookings are NOT a conflict. */
  function overlaps(startA, endA, startB, endB) {
    return minutes(startA) < minutes(endB) && minutes(startB) < minutes(endA);
  }

  function contains(periodStart, periodEnd, time) {
    return minutes(periodStart) <= minutes(time) && minutes(time) < minutes(periodEnd);
  }

  /* ================================================================== */
  /* Calendar / time-zone helpers (Asia/Macau, fixed UTC+8)              */
  /* ================================================================== */

  function dateParts(date) {
    return {
      year: parseInt(date.slice(0, 4), 10),
      month: parseInt(date.slice(5, 7), 10),
      day: parseInt(date.slice(8, 10), 10)
    };
  }

  function localDateString(date) {
    return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
  }

  /**
   * Current wall clock in Asia/Macau. Intl handles the time-zone conversion;
   * a local-clock fallback keeps the demo usable in very old browsers.
   */
  function nowInTZ() {
    try {
      var parts = new Intl.DateTimeFormat('en-US', {
        timeZone: policy.timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
      }).formatToParts(new Date());
      var fields = {};
      for (var i = 0; i < parts.length; i++) {
        fields[parts[i].type] = parts[i].value;
      }
      // Some engines render midnight as hour "24" with hour12:false.
      var hour = parseInt(fields.hour, 10) % 24;
      return {
        date: fields.year + '-' + fields.month + '-' + fields.day,
        hour: hour,
        minute: parseInt(fields.minute, 10),
        second: parseInt(fields.second, 10)
      };
    } catch (error) {
      var now = new Date();
      return {
        date: localDateString(now),
        hour: now.getHours(),
        minute: now.getMinutes(),
        second: now.getSeconds()
      };
    }
  }

  function todayISO() {
    return nowInTZ().date;
  }

  function addDays(dateISO, days) {
    var parts = dateParts(dateISO);
    var probe = new Date(Date.UTC(parts.year, parts.month - 1, parts.day + days));
    return probe.getUTCFullYear() + '-' +
      pad2(probe.getUTCMonth() + 1) + '-' +
      pad2(probe.getUTCDate());
  }

  function maxDateISO() {
    return addDays(todayISO(), policy.maxDaysAhead);
  }

  function nowMinutesOfDay() {
    var current = nowInTZ();
    return current.hour * 60 + current.minute;
  }

  function nowISO() {
    var current = nowInTZ();
    return current.date + 'T' + pad2(current.hour) + ':' +
      pad2(current.minute) + ':' + pad2(current.second) + policy.utcOffset;
  }

  /**
   * Shift a local date/time by N minutes and serialise as ISO 8601 with the
   * restaurant offset (e.g. cut-off and reminders). Macau is a fixed +08:00
   * zone, so plain wall-clock arithmetic is exact here.
   */
  function shiftISO(dateISO, hhmm, deltaMinutes) {
    var total = minutes(hhmm) + deltaMinutes;
    var dayDelta = Math.floor(total / 1440);
    var rest = total - dayDelta * 1440;
    return addDays(dateISO, dayDelta) + 'T' + formatTime(rest) + ':00' + policy.utcOffset;
  }

  function weekdayOf(dateISO) {
    var parts = dateParts(dateISO);
    var weekday = new Date(Date.UTC(parts.year, parts.month - 1, parts.day)).getUTCDay();
    return weekday === 0 ? 7 : weekday; // ISO: Monday = 1, Sunday = 7
  }

  function isPastStart(dateISO, hhmm) {
    var today = todayISO();
    if (dateISO < today) {
      return true;
    }
    if (dateISO > today) {
      return false;
    }
    return minutes(hhmm) <= nowMinutesOfDay();
  }

  /* ================================================================== */
  /* Service periods and slots                                           */
  /* ================================================================== */

  function periodsForDate(dateISO) {
    if (!isValidDate(dateISO)) {
      return [];
    }
    var weekday = weekdayOf(dateISO);
    return data.servicePeriods.filter(function (period) {
      return period.daysOfWeek.indexOf(weekday) !== -1;
    });
  }

  function slotLength(period) {
    return period.avgDiningMinutes + policy.turnoverBufferMinutes;
  }

  /** Last start time offered by a period: period.end - slot length (§5 rule 5). */
  function latestStart(period) {
    return formatTime(minutes(period.end) - slotLength(period));
  }

  function slotStarts(period) {
    var length = slotLength(period);
    var start = minutes(period.start);
    var last = minutes(period.end) - length;
    var result = [];
    for (var current = start; current <= last; current += policy.stepMinutes) {
      result.push(formatTime(current));
    }
    return result;
  }

  /**
   * The period a start time may be booked in. A start is only valid when the
   * whole booked slot fits before the period closes (PHP engine rule):
   *   period.start <= time && time + slotLength(period) <= period.end
   * So Dinner (17:30–22:00, 135 min slot) ends at 19:45, not 22:00.
   */
  function periodForTime(dateISO, hhmm) {
    var periods = periodsForDate(dateISO);
    var time = minutes(hhmm);
    for (var i = 0; i < periods.length; i++) {
      var period = periods[i];
      if (time >= minutes(period.start) && time + slotLength(period) <= minutes(period.end)) {
        return period;
      }
    }
    return null;
  }

  /** Period list with the last bookable start, used in NO_SERVICE_PERIOD details. */
  function servicePeriodDetails(dateISO) {
    return periodsForDate(dateISO).map(function (period) {
      return {
        name: period.name,
        start: period.start,
        end: period.end,
        latest_start: latestStart(period)
      };
    });
  }

  /**
   * NO_SERVICE_PERIOD: the time is outside a service period or the booking
   * would run past closing time. Details mirror the PHP API: requested_time,
   * latest_start (when the time falls inside a period) and service_periods.
   */
  function noServiceError(dateISO, hhmm) {
    var periods = periodsForDate(dateISO);
    var matched = null;
    for (var i = 0; i < periods.length; i++) {
      if (contains(periods[i].start, periods[i].end, hhmm)) {
        matched = periods[i];
        break;
      }
    }
    var latest = matched ? latestStart(matched) : null;
    var message;
    if (matched) {
      message = 'A ' + slotLength(matched) + '-minute booking starting at ' + hhmm +
        ' would finish after ' + matched.name + ' closes at ' + matched.end +
        '. The last start time is ' + latest + '.';
    } else {
      message = 'There is no service period at ' + hhmm + ' on ' + dateISO + '.';
    }
    return new DemoError('NO_SERVICE_PERIOD', message, {
      requested_time: hhmm,
      latest_start: latest,
      service_periods: servicePeriodDetails(dateISO)
    }, 422);
  }


  /**
   * Time options for the search form: one group per service period, only
   * start times that are still in the future when the date is today.
   */
  function buildTimeOptions(dateISO) {
    var groups = [];
    if (!isValidDate(dateISO)) {
      return groups;
    }
    var periods = periodsForDate(dateISO);
    for (var i = 0; i < periods.length; i++) {
      var starts = slotStarts(periods[i]);
      if (dateISO === todayISO()) {
        var nowMinutes = nowMinutesOfDay();
        starts = starts.filter(function (start) {
          return minutes(start) > nowMinutes;
        });
      }
      if (starts.length > 0) {
        groups.push({ period: periods[i], starts: starts });
      }
    }
    return groups;
  }

  /* ================================================================== */
  /* Validation helpers                                                  */
  /* ================================================================== */

  function assertDate(dateISO) {
    if (!isValidDate(dateISO)) {
      throw new DemoError(
        'VALIDATION_ERROR',
        'Enter a valid date (YYYY-MM-DD).',
        { date: 'Enter a valid date (YYYY-MM-DD).' },
        422
      );
    }
    var today = todayISO();
    var max = addDays(today, policy.maxDaysAhead);
    if (dateISO < today || dateISO > max) {
      throw new DemoError(
        'DATE_OUT_OF_RANGE',
        'The date must be between ' + today + ' and ' + max + '.',
        { min: today, max: max },
        422
      );
    }
  }

  function assertPartySize(partySize) {
    var party = typeof partySize === 'string' ? parseInt(partySize, 10) : partySize;
    if (typeof party !== 'number' || !isFinite(party) || Math.floor(party) !== party ||
        party < policy.minPartySize) {
      throw validationError({ party_size: 'Choose between 1 and 20 guests.' });
    }
    if (party > policy.maxPartySize) {
      throw new DemoError(
        'PARTY_TOO_LARGE',
        'Online booking is available for parties of up to 20 guests. Please contact the restaurant for larger groups.',
        { max_party_size: policy.maxPartySize },
        422
      );
    }
    return party;
  }

  function assertArea(area) {
    if (area === null || area === undefined || area === '' || area === 'all') {
      return null;
    }
    if (data.areaOrder.indexOf(area) === -1) {
      throw validationError({ area: 'Choose a valid seating area.' });
    }
    return area;
  }

  function findTable(tableId) {
    var numeric = typeof tableId === 'string' ? parseInt(tableId, 10) : tableId;
    if (typeof numeric !== 'number' || !isFinite(numeric)) {
      return null;
    }
    for (var i = 0; i < data.tables.length; i++) {
      if (data.tables[i].id === numeric) {
        return data.tables[i];
      }
    }
    return null;
  }

  function largestSeats(tableList) {
    var largest = 0;
    for (var i = 0; i < tableList.length; i++) {
      if (tableList[i].seats > largest) {
        largest = tableList[i].seats;
      }
    }
    return largest;
  }

  function largestTableSeats() {
    return largestSeats(data.tables);
  }

  /* ================================================================== */
  /* localStorage (with an in-memory fallback)                           */
  /* ================================================================== */

  var memoryStore = {};
  var storageProbe = (function () {
    try {
      if (!global.localStorage) {
        return false;
      }
      var probeKey = '__dinereserve_demo_probe__';
      global.localStorage.setItem(probeKey, '1');
      global.localStorage.removeItem(probeKey);
      return true;
    } catch (error) {
      return false;
    }
  })();

  function readJSON(key, fallback) {
    if (storageProbe) {
      try {
        var raw = global.localStorage.getItem(key);
        if (raw !== null && raw !== undefined && raw !== '') {
          var parsed = JSON.parse(raw);
          if (Array.isArray(parsed) || (parsed && typeof parsed === 'object')) {
            return parsed;
          }
        }
      } catch (error) {
        // Corrupt JSON or blocked storage: fall back to memory.
      }
    }
    return Object.prototype.hasOwnProperty.call(memoryStore, key)
      ? memoryStore[key]
      : fallback;
  }

  function writeJSON(key, value) {
    memoryStore[key] = value;
    if (storageProbe) {
      try {
        global.localStorage.setItem(key, JSON.stringify(value));
      } catch (error) {
        storageProbe = false; // quota / private mode: keep working in memory
      }
    }
  }

  function removeStored(key) {
    delete memoryStore[key];
    if (storageProbe) {
      try {
        global.localStorage.removeItem(key);
      } catch (error) {
        storageProbe = false;
      }
    }
  }

  function isBookingRecord(booking) {
    return booking &&
      typeof booking.reference === 'string' &&
      typeof booking.date === 'string' &&
      typeof booking.start === 'string' &&
      typeof booking.end === 'string' &&
      booking.table &&
      typeof booking.table.id === 'number';
  }

  function loadBookings() {
    var stored = readJSON(data.storage.bookingsKey, []);
    if (!Array.isArray(stored)) {
      return [];
    }
    return stored.filter(isBookingRecord);
  }

  function saveBookings(bookings) {
    writeJSON(data.storage.bookingsKey, bookings);
  }

  function activeBookingsOn(dateISO) {
    return loadBookings().filter(function (booking) {
      return booking.date === dateISO &&
        policy.blockingBookingStatuses.indexOf(booking.status) !== -1;
    });
  }

  /* ================================================================== */
  /* Availability                                                        */
  /* ================================================================== */

  function computeFreeTables(dateISO, hhmm, period, partySize, area, dayBookings) {
    var endTime = addMinutes(hhmm, slotLength(period));
    var free = [];
    for (var i = 0; i < data.tables.length; i++) {
      var table = data.tables[i];
      if (table.status !== 'FREE') {
        continue;
      }
      if (table.seats < partySize) {
        continue;
      }
      if (area && table.area !== area) {
        continue;
      }
      var taken = false;
      for (var j = 0; j < dayBookings.length; j++) {
        var booking = dayBookings[j];
        if (booking.table.id !== table.id) {
          continue;
        }
        if (overlaps(hhmm, endTime, booking.start, booking.end)) {
          taken = true;
          break;
        }
      }
      if (!taken) {
        free.push(table);
      }
    }
    return free;
  }

  function toTableSummary(table) {
    return { id: table.id, code: table.code, seats: table.seats, area: table.area };
  }

  /**
   * Engine.availability(date, time, partySize, area)
   * Returns the same shape as AvailabilityService::search() (§5), plus the
   * per-slot counts needed by the demo UI.
   *
   * @throws DemoError DATE_OUT_OF_RANGE | NO_SERVICE_PERIOD | PARTY_TOO_LARGE |
   *                   VALIDATION_ERROR
   */
  function availability(dateISO, hhmm, partySize, area) {
    assertDate(dateISO);
    if (!isValidTime(hhmm)) {
      throw validationError({ time: 'Choose a start time (HH:MM).' });
    }
    var party = assertPartySize(partySize);
    var areaFilter = assertArea(area);
    var period = periodForTime(dateISO, hhmm);
    if (!period) {
      throw noServiceError(dateISO, hhmm);
    }

    var slotMinutes = slotLength(period);
    var dayBookings = activeBookingsOn(dateISO);
    var freeAtTime = computeFreeTables(dateISO, hhmm, period, party, areaFilter, dayBookings);
    var slots = slotStarts(period).map(function (start) {
      var free = computeFreeTables(dateISO, start, period, party, areaFilter, dayBookings);
      return {
        start: start,
        end: addMinutes(start, slotMinutes),
        available_tables: free.length,
        largest_party_size: largestSeats(free)
      };
    });

    return {
      restaurant: {
        id: data.restaurant.id,
        name: data.restaurant.name,
        timezone: data.restaurant.timezone,
        address: data.restaurant.address
      },
      query: {
        date: dateISO,
        time: hhmm,
        party_size: party,
        area: areaFilter,
        service_period: period.name
      },
      service_period: {
        id: period.id,
        name: period.name,
        start: period.start,
        end: period.end,
        avg_dining_minutes: period.avgDiningMinutes,
        slot_minutes: slotMinutes
      },
      slots: slots,
      tables: freeAtTime.map(toTableSummary),
      summary: {
        available_tables: freeAtTime.length,
        largest_party_size: largestSeats(freeAtTime),
        is_available: freeAtTime.length > 0
      },
      meta: {
        generated_at: nowISO(),
        cached: false,
        cache_ttl_seconds: 30
      }
    };
  }

  /* ================================================================== */
  /* Booking creation — mirrors BookingService::create() (§6)            */
  /* ================================================================== */

  function collectBookingInput(input) {
    input = input || {};
    var errors = {};
    var clean = {};

    var name = typeof input.name === 'string' ? input.name.trim() : '';
    if (name.length < 1 || name.length > policy.maxNameLength) {
      errors.name = 'Enter your full name (1–80 characters).';
    }
    clean.name = name;

    var emailRaw = typeof input.email === 'string' ? input.email.trim() : '';
    var email = emailRaw.toLowerCase();
    if (email.length === 0) {
      errors.email = 'Enter your e-mail address.';
    } else if (email.length > policy.maxEmailLength || !EMAIL_RE.test(email)) {
      errors.email = 'Enter a valid e-mail address, e.g. name@example.com.';
    }
    clean.email = email;

    var mobile = typeof input.mobile === 'string' ? input.mobile.trim() : '';
    if (mobile.length > policy.maxMobileLength) {
      errors.mobile = 'Phone number must be 40 characters or fewer.';
    }
    clean.mobile = mobile === '' ? null : mobile;

    var special = typeof input.special_requests === 'string'
      ? input.special_requests.trim()
      : '';
    if (special.length > policy.maxSpecialRequestsLength) {
      errors.special_requests = 'Special requests must be 200 characters or fewer.';
    }
    clean.special_requests = special === '' ? null : special;

    var dateISO = typeof input.date === 'string' ? input.date : '';
    var dateMessage = null;
    try {
      assertDate(dateISO);
    } catch (error) {
      dateMessage = error.message;
    }
    if (dateMessage) {
      errors.date = dateMessage;
    }
    clean.date = dateISO;

    var hhmm = typeof input.time === 'string' ? input.time : '';
    var period = null;
    if (!isValidTime(hhmm)) {
      errors.time = 'Choose a start time.';
    } else if (!dateMessage) {
      // slot-aware: the booking must finish before the period closes
      period = periodForTime(dateISO, hhmm);
    }
    clean.time = hhmm;
    clean.period = period;

    var party = typeof input.party_size === 'string'
      ? parseInt(input.party_size, 10)
      : input.party_size;
    if (typeof party !== 'number' || !isFinite(party) || Math.floor(party) !== party ||
        party < policy.minPartySize) {
      errors.party_size = 'Choose between 1 and 20 guests.';
    } else if (party > policy.maxPartySize) {
      errors.party_size = 'Parties above 20 guests must contact the restaurant.';
    }
    clean.party_size = party;

    var table = findTable(input.table_id);
    if (!table) {
      errors.table_id = 'Choose a table from the availability list.';
    }
    clean.table = table;
    clean.table_id = table ? table.id : null;

    return { errors: errors, clean: clean };
  }

  /**
   * Public dry-run used by the UI to aggregate all field errors first.
   * Service-period problems are surfaced as a field error here; createBooking
   * raises the contract-shaped NO_SERVICE_PERIOD instead.
   */
  function validateBookingInput(input) {
    var collected = collectBookingInput(input);
    var clean = collected.clean;
    if (!collected.errors.date && isValidTime(clean.time) && !clean.period) {
      var serviceError = noServiceError(clean.date, clean.time);
      collected.errors.time = serviceError.details.latest_start
        ? 'That start time is too late for this service. The last start time is ' +
          serviceError.details.latest_start + '.'
        : 'There is no service period at ' + clean.time + ' on that date.';
    }
    return collected.errors;
  }

  function randomReference() {
    var alphabet = policy.referenceAlphabet;
    var length = policy.referenceLength;
    var cryptoObj = global.crypto || global.msCrypto;
    if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
      try {
        // 256 is an exact multiple of the 32-character alphabet, so simple
        // modulo is unbiased here.
        var buffer = new Uint8Array(length * 2);
        var out = '';
        while (out.length < length) {
          cryptoObj.getRandomValues(buffer);
          for (var i = 0; i < buffer.length && out.length < length; i++) {
            out += alphabet.charAt(buffer[i] % alphabet.length);
          }
        }
        return out;
      } catch (error) {
        // fall through to Math.random
      }
    }
    var fallback = '';
    for (var k = 0; k < length; k++) {
      fallback += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
    }
    return fallback;
  }

  function createBooking(input) {
    var collected = collectBookingInput(input);
    if (Object.keys(collected.errors).length > 0) {
      throw validationError(collected.errors);
    }
    var clean = collected.clean;
    var table = clean.table;
    var period = clean.period;

    // The requested time must leave room for the whole slot before closing:
    // time + (avg dining + turnover) <= period.end. Same rule as PHP.
    if (!period) {
      throw noServiceError(clean.date, clean.time);
    }

    // A booking start must be in the future (SLOT_IN_PAST in the PHP service).
    var startMillis = Date.parse(clean.date + 'T' + clean.time + ':00' + policy.utcOffset);
    if (!isFinite(startMillis) || startMillis <= Date.now()) {
      throw new DemoError(
        'SLOT_IN_PAST',
        'That start time has already passed. Choose a later time today or another date.',
        { date: clean.date, time: clean.time },
        422
      );
    }

    if (table.status !== 'FREE') {
      throw new DemoError(
        'TABLE_UNAVAILABLE',
        'Table ' + table.code + ' is not in service (' + table.status.toLowerCase() + ').',
        { table_id: table.id, status: table.status },
        409
      );
    }
    if (clean.party_size > table.seats) {
      throw new DemoError(
        'PARTY_TOO_LARGE',
        'Table ' + table.code + ' seats ' + table.seats + ' guests.',
        { max_party_size: table.seats, table_id: table.id },
        422
      );
    }

    var endTime = addMinutes(clean.time, slotLength(period));
    if (!isTableFree(table.id, clean.date, clean.time, endTime)) {
      var alternatives = [];
      try {
        alternatives = availability(clean.date, clean.time, clean.party_size, null)
          .tables.slice(0, 3);
      } catch (error) {
        alternatives = [];
      }
      throw new DemoError(
        'TABLE_TAKEN',
        'That table has just been taken. Please choose another available table.',
        { table_id: table.id, alternatives: alternatives },
        409
      );
    }

    var bookings = loadBookings();
    var reference = null;
    for (var attempt = 0; attempt < 5 && reference === null; attempt++) {
      var candidate = randomReference();
      var duplicate = false;
      for (var i = 0; i < bookings.length; i++) {
        if (bookings[i].reference === candidate) {
          duplicate = true;
          break;
        }
      }
      if (!duplicate) {
        reference = candidate;
      }
    }
    if (reference === null) {
      throw new DemoError(
        'CONFLICT',
        'Could not allocate a booking reference. Please try again.',
        {},
        409
      );
    }

    var nextId = 0;
    for (var j = 0; j < bookings.length; j++) {
      var existingId = Number(bookings[j].id);
      if (isFinite(existingId) && existingId > nextId) {
        nextId = existingId;
      }
    }

    var booking = {
      id: nextId + 1,
      reference: reference,
      status: 'CONFIRMED',
      date: clean.date,
      start: clean.time,
      end: endTime,
      party_size: clean.party_size,
      table: {
        id: table.id,
        code: table.code,
        seats: table.seats,
        area: table.area
      },
      guest: {
        name: clean.name,
        email: clean.email,
        mobile: clean.mobile
      },
      special_requests: clean.special_requests,
      cutoff_at: shiftISO(clean.date, clean.time, -policy.cutoffMinutes),
      reminders: [
        shiftISO(clean.date, clean.time, -policy.reminderHours[0] * 60),
        shiftISO(clean.date, clean.time, -policy.reminderHours[1] * 60)
      ],
      channel: 'online',
      created_at: nowISO()
    };

    bookings.push(booking);
    saveBookings(bookings);

    return { booking: booking, created: true };
  }

  function isTableFree(tableId, dateISO, startTime, endTime, ignoreBookingId) {
    var bookings = loadBookings();
    for (var i = 0; i < bookings.length; i++) {
      var booking = bookings[i];
      if (booking.status !== 'CONFIRMED' && booking.status !== 'SEATED') {
        continue;
      }
      if (booking.date !== dateISO) {
        continue;
      }
      if (booking.table.id !== tableId) {
        continue;
      }
      if (ignoreBookingId !== null && ignoreBookingId !== undefined &&
          Number(booking.id) === Number(ignoreBookingId)) {
        continue;
      }
      if (overlaps(startTime, endTime, booking.start, booking.end)) {
        return false;
      }
    }
    return true;
  }

  /* ================================================================== */
  /* My demo bookings / waitlist                                         */
  /* ================================================================== */

  function listBookings() {
    var bookings = loadBookings();
    return bookings.slice().sort(function (a, b) {
      var left = String(a.created_at || '');
      var right = String(b.created_at || '');
      if (left === right) {
        return Number(b.id) - Number(a.id);
      }
      return left < right ? 1 : -1;
    });
  }

  function cancelBooking(reference) {
    var bookings = loadBookings();
    for (var i = 0; i < bookings.length; i++) {
      if (bookings[i].reference !== reference) {
        continue;
      }
      if (bookings[i].status !== 'CONFIRMED' && bookings[i].status !== 'SEATED') {
        throw new DemoError(
          'CONFLICT',
          'Only confirmed bookings can be cancelled in the demo.',
          { reference: reference },
          409
        );
      }
      bookings[i].status = 'CANCELLED';
      bookings[i].cancelled_at = nowISO();
      saveBookings(bookings);
      return bookings[i];
    }
    throw new DemoError(
      'NOT_FOUND',
      'No booking with reference ' + reference + '.',
      { reference: reference },
      404
    );
  }

  function loadWaitlist() {
    var stored = readJSON(data.storage.waitlistKey, []);
    if (!Array.isArray(stored)) {
      return [];
    }
    return stored.filter(function (entry) {
      return entry && typeof entry.id === 'number' &&
        typeof entry.date === 'string' && typeof entry.start === 'string';
    });
  }

  function listWaitlist() {
    return loadWaitlist().slice().sort(function (a, b) {
      var left = String(a.created_at || '');
      var right = String(b.created_at || '');
      return left < right ? 1 : (left > right ? -1 : 0);
    });
  }

  function joinWaitlist(input) {
    input = input || {};
    var errors = {};

    var name = typeof input.name === 'string' ? input.name.trim() : '';
    if (name.length < 1 || name.length > policy.maxNameLength) {
      errors.name = 'Enter your full name (1–80 characters).';
    }
    var contact = typeof input.contact === 'string' ? input.contact.trim() : '';
    if (contact.length < 3 || contact.length > 120) {
      errors.contact = 'Enter an e-mail address or phone number.';
    }

    var dateISO = typeof input.date === 'string' ? input.date : '';
    var dateMessage = null;
    try {
      assertDate(dateISO);
    } catch (error) {
      dateMessage = error.message;
    }
    if (dateMessage) {
      errors.date = dateMessage;
    }

    var hhmm = typeof input.time === 'string' ? input.time : '';
    if (!isValidTime(hhmm)) {
      errors.time = 'Choose a start time.';
    } else if (!dateMessage && !periodForTime(dateISO, hhmm)) {
      errors.time = 'There is no service period at ' + hhmm + '.';
    }

    var party = typeof input.party_size === 'string'
      ? parseInt(input.party_size, 10)
      : input.party_size;
    if (typeof party !== 'number' || !isFinite(party) || Math.floor(party) !== party ||
        party < policy.minPartySize || party > policy.maxPartySize) {
      errors.party_size = 'Choose between 1 and 20 guests.';
    }

    var area = input.area || null;
    if (area && data.areaOrder.indexOf(area) === -1) {
      errors.area = 'Choose a valid seating area.';
    }

    if (Object.keys(errors).length > 0) {
      throw validationError(errors);
    }

    var entries = loadWaitlist();
    var nextId = 0;
    for (var i = 0; i < entries.length; i++) {
      if (Number(entries[i].id) > nextId) {
        nextId = Number(entries[i].id);
      }
    }
    var entry = {
      id: nextId + 1,
      restaurant_id: data.restaurant.id,
      date: dateISO,
      start: hhmm,
      party_size: party,
      guest_name: name,
      guest_contact: contact,
      area: area,
      status: 'WAITING',
      created_at: nowISO()
    };
    entries.push(entry);
    writeJSON(data.storage.waitlistKey, entries);
    return entry;
  }

  function removeWaitlist(id) {
    var entries = loadWaitlist();
    var remaining = entries.filter(function (entry) {
      return Number(entry.id) !== Number(id);
    });
    writeJSON(data.storage.waitlistKey, remaining);
    return remaining;
  }

  /* ================================================================== */
  /* Demo reset                                                          */
  /* ================================================================== */

  function resetDemoData() {
    memoryStore = {};
    removeStored(data.storage.bookingsKey);
    removeStored(data.storage.waitlistKey);
  }

  /* ================================================================== */
  /* Public API                                                          */
  /* ================================================================== */

  NS.Engine = {
    // error type / constants
    DemoError: DemoError,
    policy: policy,
    storageKeys: data.storage,
    restaurant: data.restaurant,
    areaLabels: data.areaLabels,
    areaOrder: data.areaOrder,

    // time + date helpers
    minutes: minutes,
    formatTime: formatTime,
    addMinutes: addMinutes,
    isValidDate: isValidDate,
    isValidTime: isValidTime,
    overlaps: overlaps,
    contains: contains,
    todayISO: todayISO,
    maxDateISO: maxDateISO,
    addDays: addDays,
    nowMinutesOfDay: nowMinutesOfDay,
    nowISO: nowISO,
    shiftISO: shiftISO,
    isPastStart: isPastStart,

    // periods / slots
    periods: function () {
      return data.servicePeriods.slice();
    },
    periodsForDate: periodsForDate,
    slotLength: slotLength,
    latestStart: latestStart,
    slotStarts: slotStarts,
    periodForTime: periodForTime,
    buildTimeOptions: buildTimeOptions,

    // availability + bookings
    availability: availability,
    validateBookingInput: validateBookingInput,
    createBooking: createBooking,
    isTableFree: isTableFree,
    largestTableSeats: largestTableSeats,

    // local demo data
    listBookings: listBookings,
    cancelBooking: cancelBooking,
    listWaitlist: listWaitlist,
    joinWaitlist: joinWaitlist,
    removeWaitlist: removeWaitlist,
    resetDemoData: resetDemoData
  };
})(typeof window !== 'undefined' ? window : this);
