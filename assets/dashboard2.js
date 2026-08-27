// NA Ops Hub — Dashboard-Logik (ausgelagert aus dashboard.php).
// Steuert das Ein-Seiten-Layout (showPage), Lager-Modals, Barcode-Scanner,
// Kalkulationsbox, Sticky Notes usw. Nur von dashboard.php eingebunden,
// spricht dessen ?api=1-Endpunkt per fetch('dashboard.php', ...) an.
//
// ── UTILS ────────────────────────────────────────────────────────────────────
function showToast(msg, error=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (error ? ' error' : '') + ' show';
  setTimeout(() => t.classList.remove('show'), 3000);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if(e.target === o) o.classList.remove('open'); });
});

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('dashboard.php', { method:'POST', body:fd });
  return res.json();
}

// ── CLOCK ────────────────────────────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('clock').textContent = now.toTimeString().slice(0,8);
  const el = document.getElementById('date-display');
  if(el) el.textContent = now.toLocaleDateString('de-DE',{weekday:'long',day:'2-digit',month:'2-digit',year:'numeric'});
}
updateClock(); setInterval(updateClock, 1000);

// Datumsfeld für Bewegungen standardmäßig auf heute
document.getElementById('b-datum').value = new Date().toISOString().slice(0,10);

// ── MEHR-MENÜ ────────────────────────────────────────────────────────────────
function toggleMehrMenu() {
  document.getElementById('mehr-overlay').classList.add('open');
}
function closeMehrMenu(event) {
  document.getElementById('mehr-overlay').classList.remove('open');
}

// ── NAV ──────────────────────────────────────────────────────────────────────
function showPage(name, navEl) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-'+name).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if(navEl) navEl.classList.add('active');
  else {
    // Falls kein Nav-Element übergeben wurde (z.B. programmatischer Aufruf),
    // trotzdem den passenden Sidebar-Eintrag markieren.
    document.querySelectorAll('.nav-item').forEach(n => {
      if (n.getAttribute('onclick') === `showPage('${name}',this)`) n.classList.add('active');
    });
  }
  // Mobile Bottom-Nav ebenfalls synchron halten
  document.querySelectorAll('.mobile-nav-item').forEach(n => {
    n.classList.toggle('active', n.dataset.page === name);
  });
  history.replaceState(null, '', '#' + name);
}

// Beim Laden: falls URL-Hash gesetzt ist (z.B. nach Reload von der Lager-Seite
// aus), direkt den richtigen Tab öffnen statt immer auf Dashboard zu landen.
(function initPageFromHash() {
  const hash = location.hash.replace('#', '');
  if (hash && document.getElementById('page-' + hash)) {
    showPage(hash, null);
  }
})();

// Reload-Helfer: merkt sich den aktuellen Tab im Hash, bevor neu geladen wird
function reloadOnCurrentPage() {
  const current = document.querySelector('.page.active')?.id.replace('page-', '') || 'dashboard';
  location.hash = current;
  location.reload();
}

// ── GEWINN-KALKULATION ──────────────────────────────────────────────────────
// Gebührensätze — hier jederzeit anpassen, falls sich Konditionen ändern.
// Alle Prozentsätze werden auf den BRUTTO-Verkaufspreis angewendet (so berechnen
// die Plattformen ihre Gebühren üblicherweise). Amazon-Monatsgebühr ist ein
// Fixkostenblock, wird NICHT pro Stück abgezogen, sondern nur als Hinweis gezeigt.
const GEBUEHREN = {
  amazon: { prozent: 0.15, monatlich_fix: 39.00, label: 'Amazon' },
  ebay:   { prozent: 0.11, anzeige_prozent: 0.05, label: 'eBay' },
  tiktok: { prozent: 0.08, label: 'TikTok Shop' },
  versand_netto: 3.50,       // DPD, ohne USt
  steuerruecklage_prozent: 0.30, // GmbH: KSt 15% + Soli ~0,8% + GewSt Bremen (Hebesatz 460%) ~16,1% ≈ 30-32%
};

function berechneKalkulation(vkBrutto, ekPreisNetto) {
  if (!vkBrutto || vkBrutto <= 0) return null;
  const vkNetto = vkBrutto / 1.19; // 19% USt raus, die geht ans Finanzamt, ist kein Gewinn

  function fuerPlattform(key) {
    const g = GEBUEHREN[key];
    let gebuehr = vkBrutto * g.prozent;
    if (g.anzeige_prozent) gebuehr += vkBrutto * g.anzeige_prozent; // eBay Anzeigegebühr
    const rohertrag = vkNetto - gebuehr - GEBUEHREN.versand_netto - ekPreisNetto;
    const ruecklage = rohertrag > 0 ? rohertrag * GEBUEHREN.steuerruecklage_prozent : 0;
    const verbleibt = rohertrag - ruecklage;
    return { label: g.label, gebuehr, rohertrag, ruecklage, verbleibt, monatlich_fix: g.monatlich_fix || null };
  }

  return {
    amazon: fuerPlattform('amazon'),
    ebay: fuerPlattform('ebay'),
    tiktok: fuerPlattform('tiktok'),
  };
}

function renderKalkulation(produktId) {
  const input = document.getElementById('vk-' + produktId);
  const box = document.getElementById('kalk-box-' + produktId);
  const grid = document.getElementById('kalk-grid-' + produktId);
  if (!input || !box || !grid) return;

  const vk = parseFloat(input.value);
  const ek = parseFloat(box.dataset.ek) || 0;
  const bestand = parseInt(box.dataset.bestand) || 0;

  const erg = berechneKalkulation(vk, ek);
  if (!erg) {
    grid.innerHTML = '<div class="empty" style="grid-column:1/-1;padding:14px;">VK-Preis eintragen für Kalkulation</div>';
    return;
  }

  const eur = (n) => n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';

  grid.innerHTML = Object.values(erg).map(p => `
    <div class="plattform-card">
      <div class="plattform-name">${p.label}</div>
      <div class="plattform-zeile"><span class="label">Gebühr/Stk</span><span class="wert">${eur(p.gebuehr)}</span></div>
      <div class="plattform-zeile"><span class="label">Versand</span><span class="wert">${eur(GEBUEHREN.versand_netto)}</span></div>
      <div class="plattform-zeile"><span class="label">EK-Wareneinsatz</span><span class="wert">${eur(ek)}</span></div>
      <div class="plattform-zeile gewinn ${p.rohertrag < 0 ? 'negativ' : ''}"><span class="label">Rohertrag/Stk</span><span class="wert">${eur(p.rohertrag)}</span></div>
      <div class="plattform-zeile ruecklage"><span class="label">Steuerrücklage (30%)</span><span class="wert">${eur(p.ruecklage)}</span></div>
      <div class="plattform-zeile verbleibt ${p.verbleibt < 0 ? 'negativ' : ''}"><span class="label">Verbleibt/Stk</span><span class="wert">${eur(p.verbleibt)}</span></div>
      ${p.monatlich_fix ? `<div style="font-family:var(--mono);font-size:8px;color:var(--text-dim);margin-top:6px;">+ ${eur(p.monatlich_fix)}/Monat Fixkosten (nicht pro Stk gerechnet)</div>` : ''}
      <div class="plattform-total">
        Bei Verkauf des gesamten Bestands (${bestand} Stk) über ${p.label}:
        <span class="total-wert">${eur(p.verbleibt * bestand)}</span>
      </div>
    </div>
  `).join('');
}

function toggleKalkulation(produktId) {
  const box = document.getElementById('kalk-box-' + produktId);
  const wurdeGeoeffnet = !box.classList.contains('open');
  box.classList.toggle('open');
  if (wurdeGeoeffnet) renderKalkulation(produktId);
}

async function saveVkPreis(produktId) {
  const input = document.getElementById('vk-' + produktId);
  const res = await api({ action: 'produkt_vk_update', id: produktId, vk_preis_brutto: input.value });
  if (res.error) { showToast(res.error, true); return; }
  showToast('VK-Preis gespeichert');
}

// ── LAGER: PRODUKTE ──────────────────────────────────────────────────────────
async function addProdukt() {
  const name = document.getElementById('p-name').value.trim();
  if(!name) { showToast('Name fehlt', true); return; }

  let foto_url = '';
  const fotoFile = document.getElementById('p-foto').files[0];
  if (fotoFile) {
    const fd = new FormData();
    fd.append('foto', fotoFile);
    const upRes = await fetch('upload.php', { method:'POST', body:fd });
    const upData = await upRes.json();
    if (upData.error) { showToast('Foto: ' + upData.error, true); return; }
    foto_url = upData.url;
  }

  const res = await api({
    action: 'produkt_add', name,
    kategorie_id: document.getElementById('p-kategorie').value,
    ek_preis_netto: document.getElementById('p-ek').value,
    vorsteuersatz: document.getElementById('p-vst').value,
    foto_url,
    ebay_sku: document.getElementById('p-ebay-sku').value,
    amazon_sku: document.getElementById('p-amazon-sku').value,
    tiktok_sku: document.getElementById('p-tiktok-sku').value,
  });
  if(res.error) { showToast(res.error, true); return; }
  showToast('Produkt hinzugefügt');
  closeModal('modal-produkt-add');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

async function archivProdukt(id, bestand) {
  if (bestand > 0) {
    showToast('Erst Bestand auf 0 bringen (verkaufen/verbrauchen)', true);
    return;
  }
  if(!confirm('Produkt ins Archiv verschieben? Historie bleibt erhalten, du kannst es jederzeit wieder reaktivieren.')) return;
  const res = await api({ action:'produkt_archivieren', id });
  if(res.error) { showToast(res.error, true); return; }
  document.getElementById('produkt-'+id)?.remove();
  showToast('Produkt archiviert');
}

let archivLoeschmodusAktiv = false;
function toggleArchivLoeschmodus() {
  archivLoeschmodusAktiv = !archivLoeschmodusAktiv;
  const btn = document.getElementById('archiv-loeschmodus-btn');
  btn.textContent = archivLoeschmodusAktiv ? '🔒 LÖSCHMODUS BEENDEN' : '🔓 LÖSCHMODUS';
  document.querySelectorAll('.archiv-delete-btn').forEach(b => b.style.display = archivLoeschmodusAktiv ? 'inline-block' : 'none');
}

async function produktEndgueltigLoeschen(id, name) {
  if (!confirm('"' + name + '" WIRKLICH ENDGÜLTIG löschen? Das entfernt auch die komplette Bewegungshistorie unwiderruflich — kann NICHT rückgängig gemacht werden!')) return;
  const res = await api({ action: 'produkt_endgueltig_loeschen', id });
  if (res.error) { showToast(res.error, true); return; }
  document.getElementById('produkt-' + id)?.remove();
  showToast('Produkt endgültig gelöscht');
}

async function openBearbeitenModal(id) {
  const res = await api({ action: 'produkt_details_holen', id });
  if (res.error) { showToast(res.error, true); return; }

  document.getElementById('be-id').value = id;
  document.getElementById('bearbeiten-titel').textContent = 'Bearbeiten — ' + res.name;
  document.getElementById('be-ek').value = res.ek_preis_netto ?? '';
  document.getElementById('be-vk').value = res.vk_preis_brutto ?? '';
  document.getElementById('be-ebay-sku').value = res.ebay_sku ?? '';
  document.getElementById('be-amazon-sku').value = res.amazon_sku ?? '';
  document.getElementById('be-tiktok-sku').value = res.tiktok_sku ?? '';
  document.getElementById('be-barcode').value = res.barcode ?? '';
  openModal('modal-produkt-bearbeiten');
}

async function produktBearbeitenSpeichern() {
  const id = document.getElementById('be-id').value;
  const res = await api({
    action: 'produkt_bearbeiten',
    id,
    ek_preis_netto: document.getElementById('be-ek').value,
    vk_preis_brutto: document.getElementById('be-vk').value,
    ebay_sku: document.getElementById('be-ebay-sku').value,
    amazon_sku: document.getElementById('be-amazon-sku').value,
    tiktok_sku: document.getElementById('be-tiktok-sku').value,
    barcode: document.getElementById('be-barcode').value,
  });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Gespeichert');
  closeModal('modal-produkt-bearbeiten');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

function openReaktivierenModal(id, name) {
  document.getElementById('react-id').value = id;
  document.getElementById('react-ek').value = '';
  document.getElementById('reaktivieren-title').textContent = 'Reaktivieren — '+name;
  openModal('modal-reaktivieren');
}

async function reaktivierenBestaetigen() {
  const id = document.getElementById('react-id').value;
  const neuer_ek = document.getElementById('react-ek').value;
  const res = await api({ action:'produkt_reaktivieren', id, neuer_ek });
  if(res.error) { showToast(res.error, true); return; }
  showToast('Produkt reaktiviert');
  closeModal('modal-reaktivieren');
  setTimeout(() => { window.location.href = 'dashboard.php#lager'; }, 800);
}

// ── LAGER: BEWEGUNGEN ───────────────────────────────────────────────────────
let aktuellerBestandFuerModal = 0;

function toggleTypFields() {
  const typ = document.getElementById('b-typ').value;
  document.querySelectorAll('.typ-fields').forEach(el => {
    el.classList.toggle('active', el.dataset.typ === typ);
  });
  const mengeInput = document.getElementById('b-menge');
  const hinweis = document.getElementById('b-menge-hinweis');
  const label = document.getElementById('b-menge-label');
  if (typ === 'korrektur') {
    mengeInput.min = '0';
    label.textContent = 'Gezählter Bestand (IST, Stück)';
    hinweis.style.display = 'block';
    hinweis.textContent = `Aktueller Buchbestand: ${aktuellerBestandFuerModal} Stk — trag hier ein, was du tatsächlich gezählt hast. Die Differenz wird automatisch als Korrektur gebucht.`;
    mengeInput.value = aktuellerBestandFuerModal;
  } else {
    mengeInput.min = '1';
    label.textContent = 'Menge (Stück)';
    if (parseInt(mengeInput.value) < 1) mengeInput.value = 1;
    hinweis.style.display = 'none';
  }
}
toggleTypFields();

function openBewegungModal(produktId, name, bestand) {
  document.getElementById('b-produkt').value = produktId;
  document.getElementById('bewegung-title').textContent = 'Bewegung — '+name;
  aktuellerBestandFuerModal = bestand ?? 0;
  document.getElementById('b-menge').value = 1;
  document.getElementById('b-datum').value = new Date().toISOString().slice(0,10);
  document.getElementById('b-typ').value = 'zugang_einkauf';
  toggleTypFields();
  openModal('modal-bewegung-add');
}

function produktGewechselt() {
  const select = document.getElementById('b-produkt');
  const selectedOption = select.options[select.selectedIndex];
  aktuellerBestandFuerModal = parseInt(selectedOption?.dataset.bestand ?? 0);
  if (document.getElementById('b-typ').value === 'korrektur') toggleTypFields();
}

function openBewegungModalGlobal() {
  const select = document.getElementById('b-produkt');
  select.selectedIndex = 0;
  const bestand = parseInt(select.options[0]?.dataset.bestand ?? 0);
  const name = select.options[0]?.textContent ?? '';
  openBewegungModal(select.value, name, bestand);
}

async function addBewegung() {
  const typ = document.getElementById('b-typ').value;
  const payload = {
    action: 'bewegung_add',
    produkt_id: document.getElementById('b-produkt').value,
    typ,
    menge: document.getElementById('b-menge').value,
    bewegt_am: document.getElementById('b-datum').value,
    notiz: document.getElementById('b-notiz').value,
  };
  if (typ === 'zugang_einkauf') payload.ek_preis_netto = document.getElementById('b-ek').value;
  if (typ === 'abgang_verkauf') {
    payload.verkaufskanal = document.getElementById('b-kanal').value;
    payload.verkaufspreis_brutto = document.getElementById('b-verkaufspreis').value;
    payload.bestellnummer = document.getElementById('b-bestellnr').value;
  }
  if (typ === 'abgang_eigenbedarf') payload.eigenbedarf_grund = document.getElementById('b-eigenbedarf-grund').value;
  if (typ === 'retoure') {
    payload.retoure_grund = document.getElementById('b-retoure-grund').value;
    payload.wieder_verkaufsfaehig = document.getElementById('b-wieder-verkaufsfaehig').value;
  }
  if (typ === 'korrektur') payload.korrektur_grund = document.getElementById('b-korrektur-grund').value;

  const res = await api(payload);
  if(res.error) { showToast(res.error, true); return; }
  showToast('Bewegung erfasst — neuer Bestand: '+res.bestand);
  closeModal('modal-bewegung-add');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

let verlaufAktuellesProdukt = { id: null, name: null };

// ── BARCODE-SCANNER (wiederverwendbar) ──────────────────────────────────────
let barcodeZielFeld = null;
let barcodeDetectorAktiv = false;
let barcodeVideoStream = null;

async function barcodeScanStarten(zielFeldId) {
  barcodeZielFeld = zielFeldId;
  document.getElementById('barcode-scan-overlay').classList.add('open');
  document.getElementById('barcode-scan-status').textContent = 'Kamera wird gestartet...';
  const viewport = document.getElementById('barcode-scan-viewport');
  viewport.innerHTML = '';

  if ('BarcodeDetector' in window) {
    try {
      const video = document.createElement('video');
      video.style.width = '100%';
      video.style.height = '100%';
      video.style.objectFit = 'cover';
      video.setAttribute('playsinline', true);
      viewport.appendChild(video);

      barcodeVideoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = barcodeVideoStream;
      await video.play();

      const detector = new BarcodeDetector();
      barcodeDetectorAktiv = true;
      document.getElementById('barcode-scan-status').textContent = 'Barcode ins Bild halten...';

      const scanSchleife = async () => {
        if (!barcodeDetectorAktiv) return;
        try {
          const codes = await detector.detect(video);
          if (codes.length > 0) {
            barcodeGefunden(codes[0].rawValue);
            return;
          }
        } catch (e) { /* weiter versuchen */ }
        requestAnimationFrame(scanSchleife);
      };
      scanSchleife();
      return;
    } catch (e) {
      console.warn('Native BarcodeDetector fehlgeschlagen, nutze QuaggaJS', e);
    }
  }

  Quagga.init({
    inputStream: {
      type: 'LiveStream',
      target: viewport,
      constraints: { facingMode: 'environment' },
    },
    decoder: { readers: ['ean_reader', 'code_128_reader', 'upc_reader'] },
  }, function (err) {
    if (err) {
      document.getElementById('barcode-scan-status').textContent = 'Kamera konnte nicht gestartet werden.';
      return;
    }
    Quagga.start();
    document.getElementById('barcode-scan-status').textContent = 'Barcode ins Bild halten...';
  });

  Quagga.onDetected(function (result) {
    barcodeGefunden(result.codeResult.code);
  });
}

async function barcodeGefunden(code) {
  document.getElementById('barcode-scan-status').textContent = 'Gefunden: ' + code + ' — suche Produkt...';
  const res = await api({ action: 'produkt_per_barcode', barcode: code });
  barcodeScanAbbrechen();

  if (res.error) { showToast(res.error, true); return; }

  const feld = document.getElementById(barcodeZielFeld);
  if (feld) {
    feld.value = res.produkt_id;
    feld.dispatchEvent(new Event('change'));
  }
  showToast(res.produkt_name + ' erkannt');
}

function barcodeScanAbbrechen() {
  document.getElementById('barcode-scan-overlay').classList.remove('open');
  barcodeDetectorAktiv = false;
  if (barcodeVideoStream) {
    barcodeVideoStream.getTracks().forEach(t => t.stop());
    barcodeVideoStream = null;
  }
  try { Quagga.stop(); } catch (e) {}
}

// ── STICKY NOTES ─────────────────────────────────────────────────────────────
let stickyGewaehlteFarbe = 'gelb';

function stickyNoteFormOeffnen() {
  document.getElementById('sticky-note-text').value = '';
  document.getElementById('sticky-note-modal-overlay').classList.add('open');
  document.getElementById('sticky-note-text').focus();
}
function stickyNoteFormSchliessen() {
  document.getElementById('sticky-note-modal-overlay').classList.remove('open');
}
function stickyFarbeWaehlen(farbe, el) {
  stickyGewaehlteFarbe = farbe;
  document.querySelectorAll('.sticky-note-farbwahl').forEach(f => f.classList.remove('aktiv'));
  el.classList.add('aktiv');
}
async function stickyNoteSpeichern() {
  const text = document.getElementById('sticky-note-text').value.trim();
  if (!text) { return; }
  const res = await api({ action: 'notiz_anlegen', text, farbe: stickyGewaehlteFarbe });
  if (res.error) { showToast(res.error, true); return; }
  window.location.reload();
}
async function notizLoeschen(id) {
  if (!confirm('Diese Notiz entfernen?')) return;
  const res = await api({ action: 'notiz_loeschen', id });
  if (res.error) { showToast(res.error, true); return; }
  document.getElementById('notiz-' + id)?.remove();
}

async function openVerlauf(id, name) {
  verlaufAktuellesProdukt = { id, name };
  document.getElementById('verlauf-title').textContent = 'Verlauf — '+name;
  document.getElementById('verlauf-body').innerHTML = '<div class="empty">Wird geladen...</div>';
  openModal('modal-verlauf');
  await verlaufNeuLaden();
}

async function verlaufNeuLaden() {
  const data = await api({ action:'lager_verlauf', id: verlaufAktuellesProdukt.id });
  if(!data.length) { document.getElementById('verlauf-body').innerHTML = '<div class="empty">Kein Verlauf</div>'; return; }
  document.getElementById('verlauf-body').innerHTML = data.map(v => {
    let detail = '';
    if (v.typ === 'abgang_verkauf') detail = `${v.verkaufskanal ?? ''} · ${v.verkaufspreis_brutto ? '€'+v.verkaufspreis_brutto : ''}`;
    if (v.typ === 'zugang_einkauf') detail = v.ek_preis_netto ? 'EK: €'+v.ek_preis_netto+'/Stk' : '';
    if (v.typ === 'retoure') detail = v.retoure_grund ?? '';
    if (v.typ === 'abgang_eigenbedarf') detail = v.eigenbedarf_grund ?? '';
    if (v.typ === 'korrektur') detail = v.korrektur_grund ?? '';
    return `
    <div class="verlauf-item" style="position:relative; padding-right:26px;">
      <span class="verlauf-typ ${v.typ}">${v.typ.replace('_',' ').toUpperCase()}</span>
      <strong>${v.menge} Stk</strong>
      <span style="color:var(--text-dim);margin-left:8px;">${new Date(v.bewegt_am).toLocaleDateString('de-DE')} · ${v.erfasst_von === 'qaf' ? 'Artis' : 'Nour'}</span>
      ${detail ? `<div style="color:var(--text-dim);margin-top:3px;">${detail}</div>` : ''}
      ${v.notiz ? `<div style="color:var(--text-dim);margin-top:2px;">💬 ${v.notiz}</div>` : ''}
      <span onclick="bewegungRueckgaengig(${v.id})" title="Diese Bewegung rückgängig machen" style="position:absolute; top:2px; right:0; font-size:11px; opacity:0.25; cursor:pointer; transition:opacity 0.15s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.25">↩</span>
    </div>`;
  }).join('');
}

async function letzteBewegungRueckgaengig(produktId, produktName) {
  if (!confirm(`Letzte Lager-Bewegung von "${produktName}" wirklich rückgängig machen?`)) return;
  const res = await api({ action: 'letzte_bewegung_rueckgaengig', produkt_id: produktId });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Letzte Bewegung rückgängig gemacht');
  setTimeout(() => reloadOnCurrentPage(), 1000);
}

async function bewegungRueckgaengig(bewegungId) {
  if (!confirm('Diese Bewegung wirklich rückgängig machen? Sie wird komplett aus der Historie entfernt (wirkt sich auch auf Umsatz/Reports aus).')) return;
  const res = await api({ action: 'bewegung_rueckgaengig', bewegung_id: bewegungId });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Bewegung rückgängig gemacht');
  await verlaufNeuLaden();
  setTimeout(() => reloadOnCurrentPage(), 1000);
}

// ── TODOS ────────────────────────────────────────────────────────────────────
async function addTodo() {
  const titel = document.getElementById('t-titel').value.trim();
  if(!titel) { showToast('Titel fehlt', true); return; }
  const res = await api({ action:'todo_add', titel, beschreibung:document.getElementById('t-desc').value, prioritaet:document.getElementById('t-prio').value, assignee:document.getElementById('t-assign').value, deadline:document.getElementById('t-deadline').value });
  if(res.error) { showToast(res.error, true); return; }
  showToast('Aufgabe erstellt');
  closeModal('modal-todo-add');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

async function updateTodoStatus(id, status) {
  const res = await api({ action:'todo_status', id, status });
  if(res.error) { showToast('Fehler', true); return; }
  showToast('Status aktualisiert');
}

async function deleteTodo(id) {
  if(!confirm('Aufgabe wirklich löschen?')) return;
  await api({ action:'todo_delete', id });
  showToast('Aufgabe gelöscht');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

// ── LAGER-VORSCHAU (Dashboard-Startseite) ───────────────────────────────────
function lagerVorschauMehrAnzeigen() {
  document.querySelectorAll('.lager-item.versteckt').forEach(el => el.classList.remove('versteckt'));
  document.getElementById('lager-vorschau-mehr-btn')?.remove();
}

// ── SENDUNGEN (manuelle Frachtverfolgung) ───────────────────────────────────
async function sendungErstellen() {
  const spediteur = document.getElementById('sd-spediteur').value.trim();
  const inhalt = document.getElementById('sd-inhalt').value.trim();
  if (!spediteur || !inhalt) { showToast('Spediteur oder Inhalt fehlt', true); return; }

  const res = await api({
    action: 'sendung_add',
    typ: document.getElementById('sd-typ').value,
    spediteur,
    tracking_nummer: document.getElementById('sd-tracking').value.trim(),
    inhalt,
    ziel: document.getElementById('sd-ziel').value.trim(),
  });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Sendung erfasst');
  closeModal('modal-sendung-add');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

async function sendungStatusAendern(id, status) {
  const res = await api({ action: 'sendung_status_aendern', id, status });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Status aktualisiert');
}

async function sendungLoeschen(id) {
  if (!confirm('Sendung wirklich löschen?')) return;
  await api({ action: 'sendung_loeschen', id });
  document.getElementById('sendung-' + id)?.remove();
  showToast('Sendung gelöscht');
}
