// Shared date-display preference for every admin page that shows a
// timestamp (created/last used/last login/etc.) — a personal,
// per-browser choice (localStorage, not synced across devices, since
// it's purely cosmetic) rather than an account-wide setting. Previously
// every admin page duplicated an identical toLocaleString()-only
// fmtDate() of its own with no way to vary the format at all; this
// replaces all of them with one shared, configurable formatter.
// Load this BEFORE any page script that calls DateFormat.format().
(function (global) {
  "use strict";

  var STORAGE_KEY = "dateFormatPref";

  function pad(n) {
    return String(n).padStart(2, "0");
  }

  function time12(d) {
    var h = d.getHours() % 12 || 12;
    var ampm = d.getHours() < 12 ? "AM" : "PM";
    return h + ":" + pad(d.getMinutes()) + " " + ampm;
  }

  // Order here is also display order in account.php's picker.
  var FORMATS = {
    locale: { label: "Browser default", fn: function (d) { return d.toLocaleString(); } },
    mdy: { label: "MM/DD/YYYY", fn: function (d) { return pad(d.getMonth() + 1) + "/" + pad(d.getDate()) + "/" + d.getFullYear() + " " + time12(d); } },
    dmy: { label: "DD/MM/YYYY", fn: function (d) { return pad(d.getDate()) + "/" + pad(d.getMonth() + 1) + "/" + d.getFullYear() + " " + time12(d); } },
    iso: { label: "YYYY-MM-DD, 24-hour", fn: function (d) { return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) + " " + pad(d.getHours()) + ":" + pad(d.getMinutes()); } },
  };

  function getPreference() {
    try {
      var v = localStorage.getItem(STORAGE_KEY);
      return FORMATS[v] ? v : "locale";
    } catch {
      return "locale";
    }
  }

  function setPreference(key) {
    if (!FORMATS[key]) {
      return;
    }
    try {
      localStorage.setItem(STORAGE_KEY, key);
    } catch {
      // Private-browsing/storage-blocked — the choice just won't
      // persist across page loads; formatting still works this load.
    }
  }

  // $iso: an ISO datetime string, or null/empty for "no value yet".
  function format(iso) {
    if (!iso) {
      return "—";
    }
    try {
      var d = new Date(iso);
      if (isNaN(d.getTime())) {
        return iso;
      }
      return FORMATS[getPreference()].fn(d);
    } catch {
      return iso;
    }
  }

  global.DateFormat = { format: format, getPreference: getPreference, setPreference: setPreference, FORMATS: FORMATS };
})(window);
