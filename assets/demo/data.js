/*
 * DineReserve demo — static dataset.
 *
 * Mirrors `database/schema.sql` + `DineReserve\Database::seedDemo()` in the PHP
 * engine: one restaurant, three service periods and 60 tables.
 * Plain script (no ES modules, no build step) so the demo works from file://
 * and from GitHub Pages. Exposes window.DineReserveDemo.data.
 */
(function (global) {
  'use strict';

  var NS = global.DineReserveDemo || (global.DineReserveDemo = {});

  /* ------------------------------------------------------------------ */
  /* Restaurant (seedDemo inserts "DineReserve Demo Restaurant")         */
  /* ------------------------------------------------------------------ */
  var restaurant = {
    id: 1,
    name: 'DineReserve Demo Restaurant',
    address: 'Rua de Nam Keng, Taipa, Macau',
    timezone: 'Asia/Macau',
    currency: 'MOP',
    languages: 'en,zh',
    priceRange: 'MOP 120–260 per person',
    rating: 4.8,
    reviewCount: 326
  };

  /* ------------------------------------------------------------------ */
  /* service_periods (seedDemo): Lunch / Afternoon Tea / Dinner          */
  /* `avgDiningMinutes` sizes the booked slot; the +15 min turnover      */
  /* buffer is applied by engine.js (Slot::TURNOVER_BUFFER_MINUTES).     */
  /* daysOfWeek uses ISO numbering, 1 = Monday ... 7 = Sunday.           */
  /* ------------------------------------------------------------------ */
  var servicePeriods = [
    {
      id: 1,
      name: 'Lunch',
      start: '11:30',
      end: '14:30',
      avgDiningMinutes: 90,
      daysOfWeek: [1, 2, 3, 4, 5, 6, 7]
    },
    {
      id: 2,
      name: 'Afternoon Tea',
      start: '15:00',
      end: '17:00',
      avgDiningMinutes: 60,
      daysOfWeek: [1, 2, 3, 4, 5, 6, 7]
    },
    {
      id: 3,
      name: 'Dinner',
      start: '17:30',
      end: '22:00',
      avgDiningMinutes: 120,
      daysOfWeek: [1, 2, 3, 4, 5, 6, 7]
    }
  ];

  /* ------------------------------------------------------------------ */
  /* tables — generated with exactly the same formulas as seedDemo():    */
  /*   seats  = [2,4,6,8][(i-1) % 4]                                     */
  /*   area   = [main,window,terrace][floor((i-1)/4) % 3]                */
  /*   status = BLOCKED when i % 17 == 0, MAINTENANCE when i % 23 == 0   */
  /* So T17/T34/T51 are blocked and T23/T46 are under maintenance.       */
  /* ------------------------------------------------------------------ */
  var CAPACITIES = [2, 4, 6, 8];
  var AREAS = ['main', 'window', 'terrace'];
  var tables = [];
  for (var i = 1; i <= 60; i++) {
    var status = 'FREE';
    if (i % 17 === 0) {
      status = 'BLOCKED';
    } else if (i % 23 === 0) {
      status = 'MAINTENANCE';
    }
    tables.push({
      id: i,
      code: i < 10 ? 'T0' + i : 'T' + i,
      seats: CAPACITIES[(i - 1) % CAPACITIES.length],
      area: AREAS[Math.floor((i - 1) / CAPACITIES.length) % AREAS.length],
      posX: 1 + ((i - 1) % 10),
      posY: 1 + Math.floor((i - 1) / 10),
      status: status
    });
  }

  /* ------------------------------------------------------------------ */
  /* Booking policy constants — name/shape matches src/Slot.php          */
  /* ------------------------------------------------------------------ */
  var policy = {
    timezone: 'Asia/Macau',
    // Macau has used a fixed UTC+8 offset since 1999 (no DST), which is what
    // the PHP engine serialises for Asia/Macau timestamps.
    utcOffset: '+08:00',
    stepMinutes: 15,               // Slot::STEP_MINUTES
    turnoverBufferMinutes: 15,     // Slot::TURNOVER_BUFFER_MINUTES
    maxDaysAhead: 90,              // Slot::MAX_DAYS_AHEAD
    minPartySize: 1,
    maxPartySize: 20,
    maxNameLength: 80,
    maxEmailLength: 254,
    maxMobileLength: 40,
    maxSpecialRequestsLength: 200,
    cutoffMinutes: 120,            // Slot::DEFAULT_CUTOFF_MINUTES
    reminderHours: [24, 2],
    // URS REQ-1.2 7.8 — no O/0/I/1, so the reference is easy to read aloud.
    referenceAlphabet: 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
    referenceLength: 8,
    // table statuses that still hold a table (AvailabilityService rule 6)
    blockingBookingStatuses: ['CONFIRMED', 'SEATED']
  };

  var areaLabels = {
    main: 'Main dining room',
    window: 'Window',
    terrace: 'Terrace'
  };

  /* ------------------------------------------------------------------ */
  /* localStorage keys used by the static demo                           */
  /* ------------------------------------------------------------------ */
  var storage = {
    bookingsKey: 'dinereserve.demo.bookings.v1',
    waitlistKey: 'dinereserve.demo.waitlist.v1'
  };

  NS.data = {
    restaurant: restaurant,
    servicePeriods: servicePeriods,
    tables: tables,
    policy: policy,
    storage: storage,
    areaLabels: areaLabels,
    areaOrder: ['main', 'window', 'terrace']
  };
})(typeof window !== 'undefined' ? window : this);
