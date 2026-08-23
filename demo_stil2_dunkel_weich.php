<?php
// DEMO — KEINE DATENBANK. Nur Fake-Daten im Browser.
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Auslagen — Stil 2 (Dunkel/Weich)</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  :root {
    --bg: #14161F; --card: #1D2030; --card2: #262A3D; --line: #2E3248;
    --text: #E4E6F0; --text-dim: #8B8FA8; --accent: #7C7CFF; --accent-soft: #7C7CFF22;
    --green: #4ADE80; --orange: #FBBF24; --red: #F87171;
    --font: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:var(--font); background:var(--bg); color:var(--text); min-height:100vh; }
  .demo-banner { background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; font-size:12px; color:var(--accent); font-weight:600; text-align:center; }
  .main { max-width:640px; margin:0 auto; padding:32px 20px 60px; }
  h1 { font-size:24px; font-weight:700; margin-bottom:4px; }
  .subtitle { font-size:13px; color:var(--text-dim); margin-bottom:26px; }

  .kpi-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:26px; }
  .kpi-card { background:var(--card); border-radius:18px; padding:18px; border:1px solid var(--line); }
  .kpi-label { font-size:11px; color:var(--text-dim); margin-bottom:8px; font-weight:500; }
  .kpi-value { font-size:24px; font-weight:700; color:var(--orange); }

  .panel { background:var(--card); border-radius:20px; padding:22px; margin-bottom:22px; border:1px solid var(--line); }
  .panel-title { font-size:15px; font-weight:600; margin-bottom:16px; color:var(--text); }
  label { display:block; font-size:12px; font-weight:500; color:var(--text-dim); margin-bottom:6px; }
  input { width:100%; background:var(--card2); border:1px solid var(--line); border-radius:12px; padding:12px 14px; font-family:var(--font); font-size:14px; color:var(--text); outline:none; margin-bottom:14px; transition:border-color 0.15s; }
  input:focus { border-color:var(--accent); }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .btn-primary { width:100%; background:var(--accent); color:#fff; border:none; border-radius:14px; padding:14px; font-size:14.5px; font-weight:600; cursor:pointer; transition:opacity 0.15s; }
  .btn-primary:hover { opacity:0.9; }
  .btn-primary:disabled { opacity:0.4; cursor:not-allowed; }
  .fehler-text { font-size:12px; color:var(--red); margin:-8px 0 12px; }

  .filter-tabs { display:flex; gap:8px; margin-bottom:18px; background:var(--card); border-radius:16px; padding:5px; border:1px solid var(--line); }
  .filter-tab { flex:1; text-align:center; padding:9px; border-radius:12px; font-size:13px; font-weight:500; color:var(--text-dim); cursor:pointer; background:none; border:none; transition:all 0.15s; }
  .filter-tab.active { background:var(--accent-soft); color:var(--accent); font-weight:600; }

  .karte { background:var(--card); border-radius:18px; padding:18px 20px; margin-bottom:12px; border:1px solid var(--line); }
  .karte.offen { border-color:#FBBF2444; }
  .kopf { display:flex; justify-content:space-between; align-items:flex-start; }
  .person-badge { font-size:11px; font-weight:600; padding:4px 11px; border-radius:20px; background:var(--accent-soft); color:var(--accent); margin-right:8px; }
  .datum-klein { font-size:11px; color:var(--text-dim); }
  .grund { font-weight:600; font-size:15px; margin-top:10px; color:var(--text); }
  .meta { font-size:12px; color:var(--text-dim); margin-top:4px; }
  .betrag { font-size:20px; font-weight:700; color:var(--orange); }
  .betrag.erstattet { color:var(--green); }
  .aktionen { display:flex; gap:8px; margin-top:14px; }
  .mini-btn { font-size:12px; font-weight:600; padding:8px 14px; border-radius:11px; border:1px solid var(--line); background:var(--card2); color:var(--text); cursor:pointer; }
  .mini-btn.gruen { background:#4ADE8022; color:var(--green); border-color:transparent; }
  .mini-btn.rot { background:#F8717122; color:var(--red); border-color:transparent; }
  .empty { text-align:center; color:var(--text-dim); font-size:13px; padding:30px; }
  .neu-eingefuegt { animation: einblenden 0.4s ease; }
  @keyframes einblenden { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }

  /* MOBILE-OPTIMIERUNGEN */
  @media (max-width: 480px) {
    .main { padding:20px 14px 50px; }
    h1 { font-size:21px; }
    .subtitle { font-size:12px; margin-bottom:20px; }

    /* Betrag + Person-Felder untereinander statt nebeneinander —
       auf schmalen Screens sonst zu gequetscht zum Tippen */
    .row-2 { grid-template-columns:1fr; gap:0; }

    .kpi-row { grid-template-columns:repeat(2, 1fr); gap:10px; margin-bottom:20px; }
    .kpi-card { padding:14px; border-radius:14px; }
    .kpi-value { font-size:20px; }

    .panel { padding:18px; border-radius:16px; margin-bottom:18px; }
    input { padding:13px 14px; font-size:16px; } /* 16px verhindert Auto-Zoom bei iPhone-Formularfeldern */
    .btn-primary { padding:16px; font-size:15px; } /* größere Touch-Fläche */

    .filter-tabs { padding:4px; }
    .filter-tab { padding:11px 6px; font-size:12.5px; }

    .karte { padding:15px 16px; border-radius:14px; }
    .kopf { flex-wrap:wrap; gap:6px; }
    .grund { font-size:14px; }
    .betrag { font-size:18px; }

    .aktionen { flex-wrap:wrap; }
    .mini-btn { flex:1 1 auto; min-width:0; padding:10px 12px; text-align:center; } /* größere Touch-Ziele, füllen die Breite */
  }
</style>
</head>
<body>
<div class="demo-banner">🧪 DESIGN-DEMO 2 / DUNKEL &amp; WEICH — nur Fake-Daten, nichts gespeichert</div>

<div class="main" x-data="auslagenApp()">
  <h1>Auslagen</h1>
  <div class="subtitle">Wer hat wie viel für die Firma vorgestreckt</div>

  <template x-if="offenJePerson().length > 0">
    <div class="kpi-row">
      <template x-for="p in offenJePerson()" :key="p.person">
        <div class="kpi-card">
          <div class="kpi-label" x-text="'Offen — ' + p.person"></div>
          <div class="kpi-value" x-text="'€' + p.summe.toLocaleString('de-DE', {minimumFractionDigits:2})"></div>
        </div>
      </template>
    </div>
  </template>

  <div class="panel">
    <div class="panel-title">Neue Auslage erfassen</div>
    <div class="row-2">
      <div><label>Person</label><input x-model="form.person" list="p2" placeholder="Name eintragen"><datalist id="p2"><option value="Artis"><option value="Nour"></datalist></div>
      <div><label>Betrag (€)</label><input type="number" step="0.01" x-model="form.betrag"></div>
    </div>
    <label>Grund</label>
    <input x-model="form.grund" placeholder="z.B. Starlink Pickup Göteborg">
    <label>Datum</label>
    <input type="date" x-model="form.datum">
    <div class="fehler-text" x-show="fehler" x-text="fehler"></div>
    <button class="btn-primary" @click="auslageAnlegen()" :disabled="speichertGerade">
      <span x-text="speichertGerade ? 'Wird gespeichert...' : '+ Erfassen'"></span>
    </button>
  </div>

  <div class="filter-tabs">
    <button class="filter-tab" :class="{active: filter === 'alle'}" @click="filter = 'alle'">Alle</button>
    <button class="filter-tab" :class="{active: filter === 'offen'}" @click="filter = 'offen'">Offen</button>
    <button class="filter-tab" :class="{active: filter === 'erstattet'}" @click="filter = 'erstattet'">Erstattet</button>
  </div>

  <template x-if="gefilterteListe().length === 0"><div class="empty">Keine Einträge</div></template>

  <template x-for="a in gefilterteListe()" :key="a.id">
    <div class="karte" :class="{offen: a.status === 'offen', 'neu-eingefuegt': a.istNeu}">
      <div class="kopf">
        <div><span class="person-badge" x-text="a.person"></span><span class="datum-klein" x-text="formatDatum(a.datum)"></span></div>
        <div class="betrag" :class="{erstattet: a.status === 'erstattet'}" x-text="'€' + parseFloat(a.betrag).toLocaleString('de-DE', {minimumFractionDigits:2})"></div>
      </div>
      <div class="grund" x-text="a.grund"></div>
      <div class="meta">
        <span x-text="a.status === 'offen' ? '⏳ Offen' : '✓ Erstattet'"></span>
        <template x-if="a.erstattet_am"><span x-text="' am ' + formatDatum(a.erstattet_am)"></span></template>
      </div>
      <div class="aktionen">
        <template x-if="a.status === 'offen'"><button class="mini-btn gruen" @click="statusAendern(a, 'erstattet')">✓ Als erstattet markieren</button></template>
        <template x-if="a.status === 'erstattet'"><button class="mini-btn" @click="statusAendern(a, 'offen')">↩ Wieder auf offen</button></template>
        <button class="mini-btn rot" @click="auslageLoeschen(a)">✕ Löschen</button>
      </div>
    </div>
  </template>
</div>

<script>
function auslagenApp() {
  return {
    liste: [
      { id: 1, person: 'Artis', betrag: '1020.37', grund: 'Starlink Pickup Göteborg (Clas Ohlson)', datum: '2026-08-17', status: 'offen', erstattet_am: null },
      { id: 2, person: 'Nour', betrag: '45.00', grund: 'Tankstelle unterwegs (Test)', datum: '2026-08-16', status: 'erstattet', erstattet_am: '2026-08-18' },
      { id: 3, person: 'Emīlija', betrag: '89.90', grund: 'Requisiten für Content-Shoot (Test)', datum: '2026-08-10', status: 'offen', erstattet_am: null },
    ],
    filter: 'alle', fehler: '', speichertGerade: false,
    form: { person: '', betrag: '', grund: '', datum: new Date().toISOString().slice(0,10) },
    gefilterteListe() { return this.filter === 'alle' ? this.liste : this.liste.filter(a => a.status === this.filter); },
    offenJePerson() {
      const s = {};
      this.liste.filter(a => a.status === 'offen').forEach(a => { s[a.person] = (s[a.person]||0) + parseFloat(a.betrag); });
      return Object.entries(s).map(([person, summe]) => ({person, summe})).sort((a,b)=>b.summe-a.summe);
    },
    formatDatum(iso) { if (!iso) return ''; const [y,m,d] = iso.split(' ')[0].split('-'); return `${d}.${m}.${y}`; },
    async auslageAnlegen() {
      this.fehler = '';
      if (!this.form.person.trim()) { this.fehler = 'Person fehlt'; return; }
      if (!this.form.betrag || this.form.betrag <= 0) { this.fehler = 'Betrag fehlt'; return; }
      if (!this.form.grund.trim()) { this.fehler = 'Grund fehlt'; return; }
      this.speichertGerade = true;
      await new Promise(r => setTimeout(r, 400));
      this.speichertGerade = false;
      this.liste.unshift({ id: Date.now(), person: this.form.person, betrag: this.form.betrag, grund: this.form.grund, datum: this.form.datum, status: 'offen', erstattet_am: null, istNeu: true });
      this.form = { person: '', betrag: '', grund: '', datum: new Date().toISOString().slice(0,10) };
    },
    statusAendern(eintrag, neuerStatus) {
      eintrag.status = neuerStatus;
      eintrag.erstattet_am = neuerStatus === 'erstattet' ? new Date().toISOString() : null;
    },
    auslageLoeschen(eintrag) {
      if (!confirm('Eintrag wirklich löschen? (nur Demo)')) return;
      this.liste = this.liste.filter(a => a.id !== eintrag.id);
    },
  };
}
</script>
</body>
</html>