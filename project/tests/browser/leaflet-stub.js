/* Minimal stand-in for Leaflet used ONLY by tests/browser/geolocation.mjs (the real library cannot be downloaded in the test sandbox). */
(function () {
  var st = { userMarkers: 0, lastPopup: null, clickMarker: null };
  var handlers = {}; window.__fp = st;
  function layer() { return { addTo: function (m) { m._layers.push(this); return this; }, bindPopup: function (c) { this._popup = c; return this; }, on: function (e, f) { (this._h = this._h || {})[e] = f; return this; }, openPopup: function () { st.lastPopup = this._key || null; }, getLatLng: function () { return this._ll; } }; }
  window.L = {
    map: function () { var m = { _layers: [], setView: function () { return m; }, fitBounds: function () { return m; }, panTo: function () { return m; }, removeLayer: function (l) { if (l._user) { st.userMarkers--; } } }; return m; },
    tileLayer: function () { return layer(); },
    latLngBounds: function (p) { return p; },
    circle: function (ll) { var l = layer(); l._ll = ll; l._user = false; return l; },
    circleMarker: function (ll, o) {
      var l = layer(); l._ll = ll; var add = l.addTo;
      var fill = o && o.fillColor;
      l.addTo = function (m) { if (fill === '#dc2626') { l._user = true; st.userMarkers++; } else { handlers[ll.join(',')] = l; } return add.call(l, m); };
      return l;
    }
  };
  // the page tags markers by popup content; resolve keys from the popup's bold name via data on cards
  var origBind = null;
  document.addEventListener('DOMContentLoaded', function () {});
  window.addEventListener('load', function () {
    var cards = document.querySelectorAll('.card.place');
    var byCoord = {};
    cards.forEach(function (c) { byCoord[parseFloat(c.getAttribute('data-lat')) + ',' + parseFloat(c.getAttribute('data-lng'))] = c.getAttribute('data-key'); });
    Object.keys(handlers).forEach(function (k) { var parts = k.split(','); handlers[k]._key = byCoord[parseFloat(parts[0]) + ',' + parseFloat(parts[1])]; });
    st.clickMarker = function (key) { Object.keys(handlers).forEach(function (k) { if (handlers[k]._key === key && handlers[k]._h && handlers[k]._h.click) { handlers[k]._h.click(); handlers[k].openPopup(); } }); };
  });
})();
