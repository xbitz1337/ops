<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/berechtigungen/berechtigungen_helper.php';
$user = currentUser();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'auslage_anlegen') {
        $person = trim($_POST['person'] ?? '');
        $betrag = (float)($_POST['betrag'] ?? 0);
        $grund = trim($_POST['grund'] ?? '');
        $datum = $_POST['datum'] ?? date('Y-m-d');
        if (!$person) { echo json_encode(['error' => 'Person fehlt']); exit; }
        if ($betrag <= 0 || !$grund) { echo json_encode(['error' => 'Betrag oder Grund fehlt']); exit; }

        $pdo->prepare("
            INSERT INTO auslagen (person, betrag, grund, datum, status, erfasst_von)
            VALUES (?, ?, ?, ?, 'offen', ?)
        ")->execute([$person, $betrag, $grund, $datum, $user['username']]);

        $id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM auslagen WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'eintrag' => $stmt->fetch(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'status_aendern') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['offen', 'erstattet'])) { echo json_encode(['error' => 'Ungültig']); exit; }

        if ($status === 'erstattet') {
            $pdo->prepare("UPDATE auslagen SET status = ?, erstattet_am = CURDATE() WHERE id = ?")->execute([$status, $id]);
        } else {
            $pdo->prepare("UPDATE auslagen SET status = ?, erstattet_am = NULL WHERE id = ?")->execute([$status, $id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'auslage_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM auslagen WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannt']);
    exit;
}

$auslagen = $pdo->query("SELECT * FROM auslagen ORDER BY status ASC, datum DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Auslagen (Alpine) — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --orange: #e67e22; --red:#e74c3c;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:700px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:1px; margin-bottom:6px; }
  .alpine-badge { font-family:var(--mono); font-size:9px; color:var(--green); background:rgba(46,204,113,0.1); border:1px solid rgba(46,204,113,0.3); padding:4px 10px; display:inline-block; margin-bottom:20px; }

  .kpi-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:20px; }
  .kpi-card { background:rgba(21,34,54,0.8); border:1px solid rgba(230,126,34,0.3); padding:16px; }
  .kpi-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); text-transform:uppercase; margin-bottom:6px; }
  .kpi-value { font-size:22px; font-weight:700; color:var(--orange); }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:18px; margin-bottom:18px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:2px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:14px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:1px; text-transform:uppercase; margin-bottom:6px; display:block; }
  .field-input { width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--sans); font-size:14px; padding:10px 12px; outline:none; margin-bottom:12px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .btn { font-family:var(--mono); font-size:10px; letter-spacing:1px; text-transform:uppercase; padding:10px 16px; cursor:pointer; border:1px solid; background:none; }
  .btn-primary { color:var(--green); border-color:rgba(46,204,113,0.4); width:100%; }
  .btn-primary:disabled { opacity:0.4; cursor:not-allowed; }
  .fehler-text { font-family:var(--mono); font-size:10px; color:var(--red); margin:-6px 0 12px; }

  .filter-tabs { display:flex; gap:6px; margin-bottom:16px; }
  .filter-tab { font-family:var(--mono); font-size:10px; padding:7px 14px; border:1px solid rgba(45,106,173,0.25); background:none; color:var(--text-dim); cursor:pointer; }
  .filter-tab.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }

  .auslage-karte { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.15); padding:14px 16px; margin-bottom:8px; }
  .auslage-karte.offen { border-color:rgba(230,126,34,0.35); }
  .auslage-kopf { display:flex; justify-content:space-between; align-items:flex-start; }
  .auslage-person { font-family:var(--mono); font-size:9px; padding:2px 8px; border-radius:8px; background:rgba(74,158,221,0.15); color:var(--blue-bright); margin-right:6px; }
  .auslage-grund { font-weight:600; font-size:14px; margin-top:6px; }
  .auslage-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:3px; }
  .auslage-betrag { font-family:var(--mono); font-size:18px; font-weight:700; color:var(--orange); }
  .auslage-betrag.erstattet { color:var(--green); }
  .auslage-aktionen { display:flex; gap:8px; margin-top:10px; }
  .mini-btn { font-family:var(--mono); font-size:9px; padding:6px 10px; border:1px solid rgba(45,106,173,0.3); background:none; color:var(--text); cursor:pointer; }
  .mini-btn.gruen { color:var(--green); border-color:rgba(46,204,113,0.4); }
  .mini-btn.rot { color:var(--red); border-color:rgba(231,76,60,0.3); }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:24px; }

  .neu-eingefuegt { animation: einblenden 0.4s ease; }
  @keyframes einblenden { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
</style>
</head>
<body>
<?php require_once __DIR__ . '/_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main" x-data="auslagenApp()">
  <div class="page-title">Auslagen</div>
  <div class="page-sub">// WER HAT WIE VIEL FÜR DIE FIRMA VORGESTRECKT</div>
  <div class="alpine-badge">⚡ ALPINE.JS-TESTVERSION — KEIN SEITEN-NEULADEN MEHR</div>

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
    <div class="panel-title">// NEUE AUSLAGE ERFASSEN</div>
    <div class="row-2">
      <div>
        <label class="field-label">Person</label>
        <input class="field-input" x-model="form.person" list="personen-vorschlaege" placeholder="Name eintragen">
        <datalist id="personen-vorschlaege">
          <option value="Artis">
          <option value="Nour">
        </datalist>
      </div>
      <div>
        <label class="field-label">Betrag (€)</label>
        <input class="field-input" type="number" step="0.01" x-model="form.betrag">
      </div>
    </div>
    <label class="field-label">Grund</label>
    <input class="field-input" x-model="form.grund" placeholder="z.B. Starlink Pickup Göteborg, Amex privat belastet">
    <label class="field-label">Datum</label>
    <input class="field-input" type="date" x-model="form.datum">
    <div class="fehler-text" x-show="fehler" x-text="fehler"></div>
    <button class="btn btn-primary" @click="auslageAnlegen()" :disabled="speichertGerade">
      <span x-text="speichertGerade ? 'Wird gespeichert...' : '+ Erfassen'"></span>
    </button>
  </div>

  <div class="filter-tabs">
    <button class="filter-tab" :class="{active: filter === 'alle'}" @click="filter = 'alle'">Alle</button>
    <button class="filter-tab" :class="{active: filter === 'offen'}" @click="filter = 'offen'">⏳ Offen</button>
    <button class="filter-tab" :class="{active: filter === 'erstattet'}" @click="filter = 'erstattet'">✓ Erstattet</button>
  </div>

  <template x-if="gefilterteListe().length === 0">
    <div class="empty">// KEINE EINTRÄGE</div>
  </template>

  <template x-for="a in gefilterteListe()" :key="a.id">
    <div class="auslage-karte" :class="{offen: a.status === 'offen', 'neu-eingefuegt': a.istNeu}">
      <div class="auslage-kopf">
        <div>
          <span class="auslage-person" x-text="a.person"></span>
          <span style="font-family:var(--mono); font-size:9px; color:var(--text-dim);" x-text="formatDatum(a.datum)"></span>
        </div>
        <div class="auslage-betrag" :class="{erstattet: a.status === 'erstattet'}" x-text="'€' + parseFloat(a.betrag).toLocaleString('de-DE', {minimumFractionDigits:2})"></div>
      </div>
      <div class="auslage-grund" x-text="a.grund"></div>
      <div class="auslage-meta">
        <span x-text="a.status === 'offen' ? '⏳ Offen' : '✓ Erstattet'"></span>
        <template x-if="a.erstattet_am"><span x-text="' am ' + formatDatum(a.erstattet_am)"></span></template>
      </div>
      <div class="auslage-aktionen">
        <template x-if="a.status === 'offen'">
          <button class="mini-btn gruen" @click="statusAendern(a, 'erstattet')">✓ Als erstattet markieren</button>
        </template>
        <template x-if="a.status === 'erstattet'">
          <button class="mini-btn" @click="statusAendern(a, 'offen')">↩ Wieder auf offen</button>
        </template>
        <button class="mini-btn rot" @click="auslageLoeschen(a)">✕ Löschen</button>
      </div>
    </div>
  </template>
</div>

<script>
function auslagenApp() {
  return {
    liste: <?= json_encode($auslagen) ?>,
    filter: 'alle',
    fehler: '',
    speichertGerade: false,
    form: { person: '', betrag: '', grund: '', datum: new Date().toISOString().slice(0,10) },

    gefilterteListe() {
      if (this.filter === 'alle') return this.liste;
      return this.liste.filter(a => a.status === this.filter);
    },

    offenJePerson() {
      const summen = {};
      this.liste.filter(a => a.status === 'offen').forEach(a => {
        summen[a.person] = (summen[a.person] || 0) + parseFloat(a.betrag);
      });
      return Object.entries(summen)
        .map(([person, summe]) => ({ person, summe }))
        .sort((a,b) => b.summe - a.summe);
    },

    formatDatum(iso) {
      if (!iso) return '';
      const [y,m,d] = iso.split(' ')[0].split('-');
      return `${d}.${m}.${y}`;
    },

    async auslageAnlegen() {
      this.fehler = '';
      if (!this.form.person.trim()) { this.fehler = 'Person fehlt'; return; }
      if (!this.form.betrag || this.form.betrag <= 0) { this.fehler = 'Betrag fehlt'; return; }
      if (!this.form.grund.trim()) { this.fehler = 'Grund fehlt'; return; }

      this.speichertGerade = true;
      const fd = new FormData();
      fd.append('api', 1);
      fd.append('action', 'auslage_anlegen');
      fd.append('person', this.form.person);
      fd.append('betrag', this.form.betrag);
      fd.append('grund', this.form.grund);
      fd.append('datum', this.form.datum);

      const res = await fetch('auslagen_alpine.php', { method:'POST', body:fd }).then(r => r.json());
      this.speichertGerade = false;

      if (res.error) { this.fehler = res.error; return; }

      res.eintrag.istNeu = true;
      this.liste.unshift(res.eintrag);
      this.form = { person: '', betrag: '', grund: '', datum: new Date().toISOString().slice(0,10) };
    },

    async statusAendern(eintrag, neuerStatus) {
      const vorherigerStatus = eintrag.status;
      eintrag.status = neuerStatus;
      eintrag.erstattet_am = neuerStatus === 'erstattet' ? new Date().toISOString() : null;

      const fd = new FormData();
      fd.append('api', 1); fd.append('action', 'status_aendern'); fd.append('id', eintrag.id); fd.append('status', neuerStatus);
      const res = await fetch('auslagen_alpine.php', { method:'POST', body:fd }).then(r => r.json());
      if (res.error) { eintrag.status = vorherigerStatus; alert(res.error); }
    },

    async auslageLoeschen(eintrag) {
      if (!confirm('Eintrag wirklich löschen?')) return;
      const fd = new FormData();
      fd.append('api', 1); fd.append('action', 'auslage_loeschen'); fd.append('id', eintrag.id);
      const res = await fetch('auslagen_alpine.php', { method:'POST', body:fd }).then(r => r.json());
      if (res.error) { alert(res.error); return; }
      this.liste = this.liste.filter(a => a.id !== eintrag.id);
    },
  };
}
</script>
</div>
</body>
</html>