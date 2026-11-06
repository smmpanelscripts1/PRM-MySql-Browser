@verbatim
<div id="petodb" hidden>
  <div class="pma">
    <aside class="pma-nav">
      <div id="pma-nav-head" class="pma-nav-head">Veritabanları</div>
      <input id="pma-filter" type="text" placeholder="Süzgeç">
      <div id="pma-tree"></div>
    </aside>
    <section class="pma-main">
      <div id="pma-crumb" class="pma-crumb"></div>
      <div id="pma-tabs" class="pma-tabs"></div>
      <div id="pma-status" class="pma-status"></div>
      <div id="pma-body" class="pma-body"></div>
    </section>
  </div>
</div>
<script>
(function () {
  var match = location.pathname.match(/\/admin\/databases\/view\/(\d+)/);
  var mount = document.getElementById('petodb');
  if (!match || !mount) {
    if (mount) mount.remove();
    return;
  }
  var hostId = match[1];
  var content = document.querySelector('section.content');
  if (content) content.appendChild(mount);
  mount.hidden = false;

  var state = {
    db: null,
    table: null,
    tab: 'structure',
    databases: [],
    tables: [],
    open: {},
    page: 1,
    filter: ''
  };
  var statusEl = document.getElementById('pma-status');
  var bodyEl = document.getElementById('pma-body');
  var LANGS = {
    tr: { databases:'Veritabanları', filter:'Süzgeç', noDatabases:'Veritabanı yok.', pickDb:'Soldan bir veritabanı seç.', structure:'Yapı', sql:'SQL', search:'Ara', export:'Dışa aktar', import:'İçe aktar', operations:'İşlemler', settings:'Ayarlar', browse:'Gözat', insert:'Ekle', empty:'Boşalt', contains:'İçerdiği kelime', filterBtn:'Süz', dropSelected:'Seçilenleri kaldır', table:'Tablo', action:'Eylem', rows:'Satır', type:'Tür', collation:'Karşılaştırma', size:'Boyut', overhead:'Ek', tablesCount:'{n} tablo', confirmDropTables:'{n} tablo kaldırılsın mı?', dropping:'Tablolar kaldırılıyor...', droppedTables:'{n} tablo kaldırıldı.', confirmEmpty:'!{name} boşaltılsın mı?', emptied:'!{name} boşaltıldı.', confirmDrop:'!{name} kaldırılsın mı?', dropped:'!{name} kaldırıldı.', column:'Sütun', nullable:'Boş', key:'Anahtar', default:'Varsayılan', extra:'Ekstra', yes:'Evet', no:'Hayır', change:'Değiştir', remove:'Kaldır', addColumn:'Sütun ekle', name:'Ad', length:'Uzunluk', scale:'Scale', save:'Kaydet', columnDropped:'Sütun kaldırıldı.', confirmDropColumn:'!{name} sütunu kaldırılsın mı?', columnSaved:'Sütun kaydedildi.', changeColumn:'Sütunu değiştir', shown:'Gösterilen satır {from} – {to} (toplam {total}).', prev:'‹ Önceki', page:'Sayfa {page} / {pages}', next:'Sonraki ›', newRow:'Yeni satır', deleteSelected:'Seçilenleri sil', noRows:'Satır yok.', edit:'Düzenle', copy:'Kopyala', delete:'Sil', cancel:'İptal', rowSaved:'Satır kaydedildi.', rowAdded:'Satır eklendi.', rowCopied:'Satır kopyalandı.', confirmDeleteRow:'Satır silinsin mi?', rowDeleted:'Satır silindi.', confirmDeleteRows:'{n} satır silinsin mi?', rowsDeleted:'{n} satır silindi.', value:'Değer', auto:'otomatik', add:'Ekle', go:'Git', affected:'{n} satır etkilendi.', queryOk:'Sorgu çalıştı.', sqlPlaceholder:'SELECT * FROM tablo LIMIT 50', allColumns:'Tüm sütunlar', operator:'Operatör', valueLabel:'Değer', results:'{n} sonuç', exportHelp:'SQL dökümü. Tablo başına en fazla 2000 satır.', exportFail:'Dışa aktarma olmadı.', importHelp:'Dosya {db} veritabanına yazılır. phpMyAdmin veya mysqldump çıktısı olur. CREATE DATABASE ve USE satırları atlanır.', chooseFile:'SQL dosyası seç', noFile:'Dosya seçilmedi', pasteSql:'Ya da SQL yapıştır', fileTooBig:'Dosya 8 MB sınırını aşıyor.', importNeed:'Önce bir SQL dosyası seç veya yapıştır.', importing:'İçe aktarılıyor...', imported:'{n} sorgu {db} veritabanına aktarıldı.', skipped:'{n} ayar satırı atlandı.', newTable:'Yeni tablo', tablePlaceholder:'tablo_adi', create:'Oluştur', emptyTable:'Tabloyu boşalt', dropTable:'Tabloyu kaldır', optimize:'Optimize et', tableCreated:'Tablo oluşturuldu.', optimized:'Optimize edildi.', language:'Dil', languageHelp:'Arayüz dili bu tarayıcıda saklanır.', saved:'Dil kaydedildi.', session:'Oturum kapandı. Sayfayı yenile.', requestFailed:'İstek başarısız' },
    en: { databases:'Databases', filter:'Filter', noDatabases:'No databases.', pickDb:'Select a database on the left.', structure:'Structure', sql:'SQL', search:'Search', export:'Export', import:'Import', operations:'Operations', settings:'Settings', browse:'Browse', insert:'Insert', empty:'Empty', contains:'Containing word', filterBtn:'Filter', dropSelected:'Drop selected', table:'Table', action:'Action', rows:'Rows', type:'Type', collation:'Collation', size:'Size', overhead:'Overhead', tablesCount:'{n} tables', confirmDropTables:'Drop {n} tables?', dropping:'Dropping tables...', droppedTables:'{n} tables dropped.', confirmEmpty:'Empty !{name}?', emptied:'!{name} emptied.', confirmDrop:'Drop !{name}?', dropped:'!{name} dropped.', column:'Column', nullable:'Null', key:'Key', default:'Default', extra:'Extra', yes:'Yes', no:'No', change:'Change', remove:'Drop', addColumn:'Add column', name:'Name', length:'Length', scale:'Scale', save:'Save', columnDropped:'Column dropped.', confirmDropColumn:'Drop column !{name}?', columnSaved:'Column saved.', changeColumn:'Change column', shown:'Showing rows {from} – {to} ({total} total).', prev:'‹ Previous', page:'Page {page} / {pages}', next:'Next ›', newRow:'New row', deleteSelected:'Delete selected', noRows:'No rows.', edit:'Edit', copy:'Copy', delete:'Delete', cancel:'Cancel', rowSaved:'Row saved.', rowAdded:'Row inserted.', rowCopied:'Row copied.', confirmDeleteRow:'Delete this row?', rowDeleted:'Row deleted.', confirmDeleteRows:'Delete {n} rows?', rowsDeleted:'{n} rows deleted.', value:'Value', auto:'automatic', add:'Insert', go:'Go', affected:'{n} rows affected.', queryOk:'Query finished.', sqlPlaceholder:'SELECT * FROM table LIMIT 50', allColumns:'All columns', operator:'Operator', valueLabel:'Value', results:'{n} results', exportHelp:'SQL dump. At most 2000 rows per table.', exportFail:'Export failed.', importHelp:'The file is written into {db}. phpMyAdmin or mysqldump output is accepted. CREATE DATABASE and USE lines are skipped.', chooseFile:'Choose SQL file', noFile:'No file selected', pasteSql:'Or paste SQL', fileTooBig:'The file exceeds the 8 MB limit.', importNeed:'Choose or paste SQL first.', importing:'Importing...', imported:'{n} statements imported into {db}.', skipped:'{n} setup lines skipped.', newTable:'New table', tablePlaceholder:'table_name', create:'Create', emptyTable:'Empty table', dropTable:'Drop table', optimize:'Optimize', tableCreated:'Table created.', optimized:'Optimized.', language:'Language', languageHelp:'The interface language is stored in this browser.', saved:'Language saved.', session:'Session expired. Reload the page.', requestFailed:'Request failed' },
    de: { databases:'Datenbanken', filter:'Filter', noDatabases:'Keine Datenbanken.', pickDb:'Wählen Sie links eine Datenbank.', structure:'Struktur', sql:'SQL', search:'Suchen', export:'Exportieren', import:'Importieren', operations:'Operationen', settings:'Einstellungen', browse:'Anzeigen', insert:'Einfügen', empty:'Leeren', contains:'Enthält Wort', filterBtn:'Filtern', dropSelected:'Auswahl löschen', table:'Tabelle', action:'Aktion', rows:'Zeilen', type:'Typ', collation:'Kollation', size:'Größe', overhead:'Überhang', tablesCount:'{n} Tabellen', confirmDropTables:'{n} Tabellen löschen?', dropping:'Tabellen werden gelöscht...', droppedTables:'{n} Tabellen gelöscht.', confirmEmpty:'!{name} leeren?', emptied:'!{name} geleert.', confirmDrop:'!{name} löschen?', dropped:'!{name} gelöscht.', column:'Spalte', nullable:'Null', key:'Schlüssel', default:'Standard', extra:'Extra', yes:'Ja', no:'Nein', change:'Ändern', remove:'Löschen', addColumn:'Spalte hinzufügen', name:'Name', length:'Länge', scale:'Scale', save:'Speichern', columnDropped:'Spalte gelöscht.', confirmDropColumn:'Spalte !{name} löschen?', columnSaved:'Spalte gespeichert.', changeColumn:'Spalte ändern', shown:'Zeilen {from} – {to} ({total} gesamt).', prev:'‹ Zurück', page:'Seite {page} / {pages}', next:'Weiter ›', newRow:'Neue Zeile', deleteSelected:'Auswahl löschen', noRows:'Keine Zeilen.', edit:'Bearbeiten', copy:'Kopieren', delete:'Löschen', cancel:'Abbrechen', rowSaved:'Zeile gespeichert.', rowAdded:'Zeile eingefügt.', rowCopied:'Zeile kopiert.', confirmDeleteRow:'Diese Zeile löschen?', rowDeleted:'Zeile gelöscht.', confirmDeleteRows:'{n} Zeilen löschen?', rowsDeleted:'{n} Zeilen gelöscht.', value:'Wert', auto:'automatisch', add:'Einfügen', go:'Ausführen', affected:'{n} Zeilen betroffen.', queryOk:'Abfrage ausgeführt.', sqlPlaceholder:'SELECT * FROM tabelle LIMIT 50', allColumns:'Alle Spalten', operator:'Operator', valueLabel:'Wert', results:'{n} Ergebnisse', exportHelp:'SQL-Dump. Höchstens 2000 Zeilen pro Tabelle.', exportFail:'Export fehlgeschlagen.', importHelp:'Die Datei wird in {db} geschrieben. phpMyAdmin- oder mysqldump-Ausgabe ist möglich. CREATE DATABASE und USE werden übersprungen.', chooseFile:'SQL-Datei wählen', noFile:'Keine Datei gewählt', pasteSql:'Oder SQL einfügen', fileTooBig:'Die Datei überschreitet 8 MB.', importNeed:'Zuerst eine SQL-Datei wählen oder einfügen.', importing:'Import läuft...', imported:'{n} Anweisungen nach {db} importiert.', skipped:'{n} Einstellungszeilen übersprungen.', newTable:'Neue Tabelle', tablePlaceholder:'tabellenname', create:'Erstellen', emptyTable:'Tabelle leeren', dropTable:'Tabelle löschen', optimize:'Optimieren', tableCreated:'Tabelle erstellt.', optimized:'Optimiert.', language:'Sprache', languageHelp:'Die Oberflächensprache wird in diesem Browser gespeichert.', saved:'Sprache gespeichert.', session:'Sitzung abgelaufen. Seite neu laden.', requestFailed:'Anfrage fehlgeschlagen' },
    fr: { databases:'Bases de données', filter:'Filtre', noDatabases:'Aucune base.', pickDb:'Choisissez une base à gauche.', structure:'Structure', sql:'SQL', search:'Rechercher', export:'Exporter', import:'Importer', operations:'Opérations', settings:'Paramètres', browse:'Afficher', insert:'Insérer', empty:'Vider', contains:'Contenant le mot', filterBtn:'Filtrer', dropSelected:'Supprimer la sélection', table:'Table', action:'Action', rows:'Lignes', type:'Type', collation:'Interclassement', size:'Taille', overhead:'Perte', tablesCount:'{n} tables', confirmDropTables:'Supprimer {n} tables ?', dropping:'Suppression des tables...', droppedTables:'{n} tables supprimées.', confirmEmpty:'Vider !{name} ?', emptied:'!{name} vidée.', confirmDrop:'Supprimer !{name} ?', dropped:'!{name} supprimée.', column:'Colonne', nullable:'Null', key:'Clé', default:'Défaut', extra:'Extra', yes:'Oui', no:'Non', change:'Modifier', remove:'Supprimer', addColumn:'Ajouter une colonne', name:'Nom', length:'Longueur', scale:'Échelle', save:'Enregistrer', columnDropped:'Colonne supprimée.', confirmDropColumn:'Supprimer la colonne !{name} ?', columnSaved:'Colonne enregistrée.', changeColumn:'Modifier la colonne', shown:'Lignes {from} – {to} ({total} au total).', prev:'‹ Précédent', page:'Page {page} / {pages}', next:'Suivant ›', newRow:'Nouvelle ligne', deleteSelected:'Supprimer la sélection', noRows:'Aucune ligne.', edit:'Modifier', copy:'Copier', delete:'Supprimer', cancel:'Annuler', rowSaved:'Ligne enregistrée.', rowAdded:'Ligne insérée.', rowCopied:'Ligne copiée.', confirmDeleteRow:'Supprimer cette ligne ?', rowDeleted:'Ligne supprimée.', confirmDeleteRows:'Supprimer {n} lignes ?', rowsDeleted:'{n} lignes supprimées.', value:'Valeur', auto:'automatique', add:'Insérer', go:'Exécuter', affected:'{n} lignes affectées.', queryOk:'Requête exécutée.', sqlPlaceholder:'SELECT * FROM table LIMIT 50', allColumns:'Toutes les colonnes', operator:'Opérateur', valueLabel:'Valeur', results:'{n} résultats', exportHelp:'Export SQL. 2000 lignes maximum par table.', exportFail:'Échec de l\'export.', importHelp:'Le fichier est écrit dans {db}. Un export phpMyAdmin ou mysqldump convient. Les lignes CREATE DATABASE et USE sont ignorées.', chooseFile:'Choisir un fichier SQL', noFile:'Aucun fichier', pasteSql:'Ou coller le SQL', fileTooBig:'Le fichier dépasse 8 Mo.', importNeed:'Choisissez ou collez d\'abord le SQL.', importing:'Importation...', imported:'{n} requêtes importées dans {db}.', skipped:'{n} lignes de réglage ignorées.', newTable:'Nouvelle table', tablePlaceholder:'nom_table', create:'Créer', emptyTable:'Vider la table', dropTable:'Supprimer la table', optimize:'Optimiser', tableCreated:'Table créée.', optimized:'Optimisée.', language:'Langue', languageHelp:'La langue de l\'interface est enregistrée dans ce navigateur.', saved:'Langue enregistrée.', session:'Session expirée. Rechargez la page.', requestFailed:'Requête échouée' },
    ru: { databases:'Базы данных', filter:'Фильтр', noDatabases:'Нет баз данных.', pickDb:'Выберите базу слева.', structure:'Структура', sql:'SQL', search:'Поиск', export:'Экспорт', import:'Импорт', operations:'Операции', settings:'Настройки', browse:'Обзор', insert:'Вставка', empty:'Очистить', contains:'Содержит слово', filterBtn:'Фильтр', dropSelected:'Удалить выбранные', table:'Таблица', action:'Действие', rows:'Строки', type:'Тип', collation:'Сравнение', size:'Размер', overhead:'Фрагментация', tablesCount:'{n} таблиц', confirmDropTables:'Удалить {n} таблиц?', dropping:'Таблицы удаляются...', droppedTables:'Удалено таблиц: {n}.', confirmEmpty:'Очистить !{name}?', emptied:'!{name} очищена.', confirmDrop:'Удалить !{name}?', dropped:'!{name} удалена.', column:'Столбец', nullable:'Null', key:'Ключ', default:'По умолчанию', extra:'Дополнительно', yes:'Да', no:'Нет', change:'Изменить', remove:'Удалить', addColumn:'Добавить столбец', name:'Имя', length:'Длина', scale:'Масштаб', save:'Сохранить', columnDropped:'Столбец удалён.', confirmDropColumn:'Удалить столбец !{name}?', columnSaved:'Столбец сохранён.', changeColumn:'Изменить столбец', shown:'Строки {from} – {to} (всего {total}).', prev:'‹ Назад', page:'Страница {page} / {pages}', next:'Далее ›', newRow:'Новая строка', deleteSelected:'Удалить выбранные', noRows:'Нет строк.', edit:'Править', copy:'Копировать', delete:'Удалить', cancel:'Отмена', rowSaved:'Строка сохранена.', rowAdded:'Строка добавлена.', rowCopied:'Строка скопирована.', confirmDeleteRow:'Удалить эту строку?', rowDeleted:'Строка удалена.', confirmDeleteRows:'Удалить {n} строк?', rowsDeleted:'Удалено строк: {n}.', value:'Значение', auto:'авто', add:'Вставить', go:'Выполнить', affected:'Затронуто строк: {n}.', queryOk:'Запрос выполнен.', sqlPlaceholder:'SELECT * FROM table LIMIT 50', allColumns:'Все столбцы', operator:'Оператор', valueLabel:'Значение', results:'{n} результатов', exportHelp:'SQL-дамп. Не больше 2000 строк на таблицу.', exportFail:'Экспорт не удался.', importHelp:'Файл записывается в {db}. Подходит вывод phpMyAdmin или mysqldump. Строки CREATE DATABASE и USE пропускаются.', chooseFile:'Выбрать SQL-файл', noFile:'Файл не выбран', pasteSql:'Или вставьте SQL', fileTooBig:'Файл больше 8 МБ.', importNeed:'Сначала выберите или вставьте SQL.', importing:'Импорт...', imported:'Запросов импортировано в {db}: {n}.', skipped:'Пропущено служебных строк: {n}.', newTable:'Новая таблица', tablePlaceholder:'имя_таблицы', create:'Создать', emptyTable:'Очистить таблицу', dropTable:'Удалить таблицу', optimize:'Оптимизировать', tableCreated:'Таблица создана.', optimized:'Оптимизировано.', language:'Язык', languageHelp:'Язык интерфейса хранится в этом браузере.', saved:'Язык сохранён.', session:'Сессия истекла. Обновите страницу.', requestFailed:'Запрос не выполнен' }
  };
  state.lang = (function () {
    var saved = '';
    try { saved = localStorage.getItem('petodb.lang') || ''; } catch (error) {}
    if (LANGS[saved]) return saved;
    var nav = (navigator.language || 'tr').toLowerCase().slice(0, 2);
    return LANGS[nav] ? nav : 'tr';
  })();

  function t(key, vars) {
    var pack = LANGS[state.lang] || LANGS.tr;
    var text = pack[key] || LANGS.en[key] || key;
    if (!vars) return text;
    Object.keys(vars).forEach(function (name) {
      text = text.split('{' + name + '}').join(String(vars[name]));
    });
    return text;
  }

  function applyStatic() {
    document.getElementById('pma-nav-head').textContent = t('databases');
    document.getElementById('pma-filter').placeholder = t('filter');
  }

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
  }

  function fmtSize(bytes) {
    var size = Number(bytes) || 0;
    if (size < 1024) return size + ' B';
    if (size < 1048576) return (size / 1024).toFixed(1) + ' KiB';
    return (size / 1048576).toFixed(1) + ' MiB';
  }

  function ico(name) {
    var paths = {
      browse: '<path d="M1.5 3h13v10h-13z" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 6h13M5.5 6v7" stroke="currentColor" stroke-width="1.2"/>',
      structure: '<path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z" fill="none" stroke="currentColor" stroke-width="1.2"/>',
      sql: '<path d="M2 2.5h12v11H2z" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M4 6.5h6M4 9.5h3.5" stroke="currentColor" stroke-width="1.2"/>',
      search: '<circle cx="7" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M10.2 10.2L14 14" stroke="currentColor" stroke-width="1.3"/>',
      insert: '<path d="M8 2.5v11M2.5 8h11" stroke="currentColor" stroke-width="1.4"/>',
      export: '<path d="M8 2v8M4.8 7.2L8 10.4l3.2-3.2M3 13.5h10" fill="none" stroke="currentColor" stroke-width="1.3"/>',
      import: '<path d="M8 10.5V2.5M4.8 5.2L8 2.2l3.2 3M3 13.5h10" fill="none" stroke="currentColor" stroke-width="1.3"/>',
      operations: '<circle cx="8" cy="8" r="2.1" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M8 1.6v2.1M8 12.3v2.1M1.6 8h2.1M12.3 8h2.1M3.3 3.3l1.5 1.5M11.2 11.2l1.5 1.5M3.3 12.7l1.5-1.5M11.2 4.8l1.5-1.5" stroke="currentColor" stroke-width="1.15"/>',
      settings: '<path d="M3 4.5h10M3 8h10M3 11.5h10" stroke="currentColor" stroke-width="1.2"/><circle cx="6" cy="4.5" r="1.3" fill="#fff" stroke="currentColor" stroke-width="1.2"/><circle cx="10.5" cy="8" r="1.3" fill="#fff" stroke="currentColor" stroke-width="1.2"/><circle cx="7" cy="11.5" r="1.3" fill="#fff" stroke="currentColor" stroke-width="1.2"/>',
      database: '<ellipse cx="8" cy="3.6" rx="5.2" ry="2" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M2.8 3.6v8.2c0 1.1 2.3 2 5.2 2s5.2-.9 5.2-2V3.6M2.8 7.7c0 1.1 2.3 2 5.2 2s5.2-.9 5.2-2" fill="none" stroke="currentColor" stroke-width="1.2"/>',
      table: '<rect x="1.6" y="2.4" width="12.8" height="11.2" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M1.6 6h12.8M6 6v7.6" stroke="currentColor" stroke-width="1.2"/>',
      edit: '<path d="M2.2 12.6l.7-2.6L11.2 1.7l2.1 2.1-8.3 8.3-2.8.5z" fill="none" stroke="currentColor" stroke-width="1.2"/>',
      copy: '<rect x="5.2" y="3.2" width="7.6" height="9.6" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M3.2 11.2V2.4h7.4" fill="none" stroke="currentColor" stroke-width="1.2"/>',
      trash: '<path d="M3 4.2h10M6.2 4.2V2.6h3.6v1.6M4.4 4.2l.6 9h6l.6-9" fill="none" stroke="currentColor" stroke-width="1.2"/>',
      empty: '<path d="M2.4 4.2h11.2M4.2 4.2l.4 8.2h6.8l.4-8.2M6.2 6.6v3.6M9.8 6.6v3.6" fill="none" stroke="currentColor" stroke-width="1.15"/>',
      key: '<circle cx="5.6" cy="8" r="2.3" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M7.8 8H14M11.4 8v1.8M13.2 8v1.8" stroke="currentColor" stroke-width="1.2"/>'
    };
    return '<svg class="pma-ico" viewBox="0 0 16 16" aria-hidden="true">' + (paths[name] || '') + '</svg>';
  }

  function act(icon, label, attrs, tone) {
    var extra = tone === true || tone === 'danger' ? ' danger' : (tone ? ' ' + tone : '');
    return '<button type="button" class="pma-act' + extra + '" ' + attrs + '>' + ico(icon) + esc(label) + '</button>';
  }

  function setStatus(message, ok, sql) {
    if (!message) {
      statusEl.innerHTML = '';
      return;
    }
    var html = '<div class="pma-alert ' + (ok ? 'ok' : 'bad') + '"><div class="pma-alert-text">' + esc(message) + '</div>';
    if (sql) html += '<pre class="pma-sqlfail">' + esc(sql) + '</pre>';
    statusEl.innerHTML = html + '</div>';
  }

  function api(path, options) {
    var token = document.querySelector('meta[name="_token"]').getAttribute('content');
    return fetch('/extensions/petodb/hosts/' + hostId + path, Object.assign({
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
        'X-PetoDB-Lang': state.lang
      }
    }, options || {})).then(function (response) {
      return response.text().then(function (text) {
        var data = {};
        try { data = text ? JSON.parse(text) : {}; } catch (error) { data = { message: t('session') }; }
        if (!response.ok) {
          var error = new Error(data.message || t('requestFailed'));
          error.sql = data.sql || '';
          throw error;
        }
        return data;
      });
    });
  }

  function dbTabs() {
    return [
      ['structure', t('structure'), 'structure'],
      ['sql', t('sql'), 'sql'],
      ['search', t('search'), 'search'],
      ['export', t('export'), 'export'],
      ['import', t('import'), 'import'],
      ['operations', t('operations'), 'operations'],
      ['settings', t('settings'), 'settings']
    ];
  }

  function tableTabs() {
    return [
      ['browse', t('browse'), 'browse'],
      ['structure', t('structure'), 'structure'],
      ['sql', t('sql'), 'sql'],
      ['search', t('search'), 'search'],
      ['insert', t('insert'), 'insert'],
      ['export', t('export'), 'export'],
      ['operations', t('operations'), 'operations'],
      ['settings', t('settings'), 'settings']
    ];
  }

  function renderChrome() {
    var crumb = '';
    if (state.db) crumb += '<span class="pma-crumb-item">' + ico('database') + esc(state.db) + '</span>';
    if (state.table) crumb += '<span class="pma-sep">›</span><span class="pma-crumb-item">' + ico('table') + esc(state.table) + '</span>';
    document.getElementById('pma-crumb').innerHTML = crumb;
    var tabs = state.table ? tableTabs() : dbTabs();
    document.getElementById('pma-tabs').innerHTML = tabs.map(function (tab) {
      return '<button type="button" data-tab="' + tab[0] + '" class="' + (state.tab === tab[0] ? 'is-active' : '') + '">' + ico(tab[2]) + '<span>' + tab[1] + '</span></button>';
    }).join('');
    document.getElementById('pma-tabs').querySelectorAll('button').forEach(function (button) {
      button.addEventListener('click', function () {
        state.tab = button.getAttribute('data-tab');
        state.page = 1;
        renderBody();
        renderChrome();
      });
    });
  }

  function renderTree() {
    var filter = state.filter.toLowerCase();
    var html = '';
    state.databases.forEach(function (item) {
      var tables = state.db === item.name ? state.tables : [];
      var visibleTables = tables.filter(function (table) { return !filter || table.name.toLowerCase().indexOf(filter) !== -1; });
      if (filter && item.name.toLowerCase().indexOf(filter) === -1 && !visibleTables.length) return;
      var opened = state.open[item.name] !== false;
      html += '<div class="pma-db"><div class="pma-db-row">' +
        '<button type="button" class="pma-disclosure" data-toggle="' + esc(item.name) + '">' + (opened ? '▾' : '▸') + '</button>' +
        '<button type="button" class="pma-db-name ' + (state.db === item.name && !state.table ? 'is-active' : '') + '" data-db="' + esc(item.name) + '">' + ico('database') + '<span>' + esc(item.name) + '</span></button>' +
        '</div>';
      if (opened && state.db === item.name) {
        html += '<div class="pma-tables">';
        (filter ? visibleTables : tables).forEach(function (table) {
          html += '<div class="pma-table-row"><button type="button" class="pma-table-name ' + (state.table === table.name ? 'is-active' : '') + '" data-table="' + esc(table.name) + '">' + ico('table') + '<span>' + esc(table.name) + '</span></button></div>';
        });
        html += '</div>';
      }
      html += '</div>';
    });
    var tree = document.getElementById('pma-tree');
    tree.innerHTML = html || '<div>' + esc(t('noDatabases')) + '</div>';
    tree.querySelectorAll('[data-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        var name = button.getAttribute('data-toggle');
        state.open[name] = state.open[name] === false;
        renderTree();
      });
    });
    tree.querySelectorAll('[data-db]').forEach(function (button) {
      button.addEventListener('click', function () { selectDb(button.getAttribute('data-db')); });
    });
    tree.querySelectorAll('[data-table]').forEach(function (button) {
      button.addEventListener('click', function () { selectTable(button.getAttribute('data-table')); });
    });
  }

  function loadDatabases() {
    return api('/databases').then(function (data) {
      state.databases = data.databases || [];
      renderTree();
      if (!state.db && state.databases.length) selectDb(state.databases[0].name);
      else renderBody();
    }).catch(function (error) { setStatus(error.message, false); });
  }

  function selectDb(name) {
    state.db = name;
    state.table = null;
    state.tab = 'structure';
    state.page = 1;
    state.open[name] = true;
    setStatus('', true);
    api('/databases/' + encodeURIComponent(name) + '/tables').then(function (data) {
      state.tables = data.tables || [];
      renderTree();
      renderChrome();
      renderBody();
    }).catch(function (error) { setStatus(error.message, false); });
  }

  function selectTable(name, tab) {
    state.table = name;
    state.tab = tab || 'browse';
    state.page = 1;
    renderTree();
    renderChrome();
    renderBody();
  }

  function renderBody() {
    renderChrome();
    if (state.tab === 'settings') {
      renderSettings();
      return;
    }
    if (!state.db) {
      bodyEl.innerHTML = '<p>' + esc(t('pickDb')) + '</p>';
      return;
    }
    if (state.table && (state.tab === 'browse' || state.tab === 'structure' || state.tab === 'insert')) {
      api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '?page=' + state.page).then(function (data) {
        if (state.tab === 'browse') renderBrowse(data);
        else if (state.tab === 'structure') renderTableStructure(data);
        else renderInsert(data);
      }).catch(function (error) { setStatus(error.message, false); });
      return;
    }
    if (state.tab === 'structure') renderDbStructure();
    else if (state.tab === 'sql') renderSql();
    else if (state.tab === 'search') renderSearch();
    else if (state.tab === 'export') renderExport();
    else if (state.tab === 'import') renderImport();
    else if (state.tab === 'operations') renderOperations();
  }

  function renderDbStructure() {
    var filter = (document.getElementById('pma-word') || {}).value || '';
    var rows = state.tables.filter(function (table) {
      return !filter || table.name.toLowerCase().indexOf(filter.toLowerCase()) !== -1;
    });
    var totalRows = 0;
    var totalSize = 0;
    var html = '<form id="pma-word-form" class="pma-bar"><label>' + esc(t('contains')) + ' <input id="pma-word" type="text" value="' + esc(filter) + '"></label><button class="pma-btn" type="submit">' + esc(t('filterBtn')) + '</button><button type="button" id="pma-drop-tables" class="pma-btn danger" disabled>' + ico('trash') + esc(t('dropSelected')) + '</button></form>';
    html += '<div class="pma-scroll"><table class="pma-table"><thead><tr><th class="pma-check"><input type="checkbox" id="pma-tables-all"></th><th>' + esc(t('table')) + '</th><th>' + esc(t('action')) + '</th><th class="pma-num">' + esc(t('rows')) + '</th><th>' + esc(t('type')) + '</th><th>' + esc(t('collation')) + '</th><th class="pma-num">' + esc(t('size')) + '</th><th class="pma-num">' + esc(t('overhead')) + '</th></tr></thead><tbody>';
    rows.forEach(function (table) {
      totalRows += table.rows;
      totalSize += table.size;
      var name = esc(table.name);
      html += '<tr><td class="pma-check"><input type="checkbox" class="pma-table-pick" value="' + name + '"></td><td><button type="button" class="pma-name" data-act="browse" data-name="' + name + '">' + name + '</button></td><td class="pma-ops">' +
        act('browse', t('browse'), 'data-act="browse" data-name="' + name + '"') +
        act('structure', t('structure'), 'data-act="structure" data-name="' + name + '"') +
        act('search', t('search'), 'data-act="search" data-name="' + name + '"') +
        act('insert', t('insert'), 'data-act="insert" data-name="' + name + '"') +
        act('empty', t('empty'), 'data-act="truncate" data-name="' + name + '"', 'warn') +
        act('trash', t('remove'), 'data-act="drop" data-name="' + name + '"', true) +
        '</td><td class="pma-num">' + table.rows + '</td><td>' + esc(table.engine) + '</td><td>' + esc(table.collation) + '</td><td class="pma-num">' + fmtSize(table.size) + '</td><td class="pma-num">' + fmtSize(table.overhead) + '</td></tr>';
    });
    html += '</tbody><tfoot><tr><th></th><th colspan="2">' + esc(t('tablesCount', { n: rows.length })) + '</th><th class="pma-num">' + totalRows + '</th><th></th><th></th><th class="pma-num">' + fmtSize(totalSize) + '</th><th></th></tr></tfoot></table></div>';
    bodyEl.innerHTML = html;
    function pickedTables() {
      return Array.prototype.map.call(bodyEl.querySelectorAll('.pma-table-pick:checked'), function (box) { return box.value; });
    }
    function refreshTableDelete() {
      document.getElementById('pma-drop-tables').disabled = !pickedTables().length;
    }
    document.getElementById('pma-word-form').addEventListener('submit', function (event) {
      event.preventDefault();
      renderDbStructure();
    });
    bodyEl.querySelectorAll('.pma-table-pick').forEach(function (box) {
      box.addEventListener('change', refreshTableDelete);
    });
    document.getElementById('pma-tables-all').addEventListener('change', function () {
      var checked = this.checked;
      bodyEl.querySelectorAll('.pma-table-pick').forEach(function (box) { box.checked = checked; });
      refreshTableDelete();
    });
    document.getElementById('pma-drop-tables').addEventListener('click', function () {
      var names = pickedTables();
      if (!names.length || !window.confirm(t('confirmDropTables', { n: names.length }))) return;
      setStatus(t('dropping'), true);
      var chain = Promise.resolve();
      names.forEach(function (name) {
        chain = chain.then(function () {
          return api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(name), { method: 'DELETE' });
        });
      });
      chain.then(function () {
        state.table = null;
        selectDb(state.db);
        setStatus(t('droppedTables', { n: names.length }), true);
      }).catch(function (error) {
        selectDb(state.db);
        setStatus(error.message, false);
      });
    });
    bodyEl.querySelectorAll('[data-act]').forEach(function (button) {
      button.addEventListener('click', function () {
        var name = button.getAttribute('data-name');
        var act = button.getAttribute('data-act');
        if (act === 'truncate') return emptyTable(name);
        if (act === 'drop') return dropTable(name);
        selectTable(name, act === 'browse' ? 'browse' : act);
      });
    });
  }

  function emptyTable(name) {
    if (!window.confirm(t('confirmEmpty', { name: name }))) return;
    api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(name) + '/truncate', { method: 'POST', body: '{}' })
      .then(function () { setStatus(t('emptied', { name: name }), true); selectDb(state.db); })
      .catch(function (error) { setStatus(error.message, false); });
  }

  function dropTable(name) {
    if (!window.confirm(t('confirmDrop', { name: name }))) return;
    api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(name), { method: 'DELETE' })
      .then(function () { setStatus(t('dropped', { name: name }), true); state.table = null; selectDb(state.db); })
      .catch(function (error) { setStatus(error.message, false); });
  }

  function renderTableStructure(data) {
    var html = '<div class="pma-scroll"><table class="pma-table"><thead><tr><th class="pma-num">#</th><th>' + esc(t('column')) + '</th><th>' + esc(t('type')) + '</th><th>' + esc(t('collation')) + '</th><th>' + esc(t('nullable')) + '</th><th>' + esc(t('key')) + '</th><th>' + esc(t('default')) + '</th><th>' + esc(t('extra')) + '</th><th>' + esc(t('action')) + '</th></tr></thead><tbody>';
    data.columns.forEach(function (column, index) {
      var name = esc(column.name);
      html += '<tr><td class="pma-num">' + (index + 1) + '</td><td>' + name + (column.key === 'PRI' ? ' <span class="pma-key">PK</span>' : '') + '</td><td>' + esc(column.column_type) + '</td><td>' + esc(column.collation) + '</td><td>' + (column.nullable ? esc(t('yes')) : esc(t('no'))) + '</td><td>' + esc(column.key) + '</td><td>' + esc(column.default) + '</td><td>' + esc(column.extra) + '</td><td class="pma-ops">' +
        act('edit', t('change'), 'data-edit="' + name + '"') +
        act('trash', t('remove'), 'data-drop="' + name + '"', true) +
        '</td></tr>';
    });
    html += '</tbody></table></div>';
    html += '<div class="pma-box"><h4>' + esc(t('addColumn')) + '</h4><form id="pma-column" class="pma-bar">' +
      '<label>' + esc(t('name')) + ' <input name="name" type="text" required></label>' +
      '<label>' + esc(t('type')) + ' <select name="type"><option>int</option><option>bigint</option><option>varchar</option><option>text</option><option>boolean</option><option>decimal</option><option>datetime</option><option>date</option><option>json</option><option>float</option><option>double</option></select></label>' +
      '<label>' + esc(t('length')) + ' <input name="length" type="number" min="1"></label>' +
      '<label>' + esc(t('scale')) + ' <input name="scale" type="number" min="0"></label>' +
      '<label><input name="nullable" type="checkbox" checked> NULL</label>' +
      '<button class="pma-btn primary" type="submit">' + esc(t('save')) + '</button></form></div>';
    bodyEl.innerHTML = html;
    bodyEl.querySelectorAll('[data-drop]').forEach(function (button) {
      button.addEventListener('click', function () {
        var name = button.getAttribute('data-drop');
        if (!window.confirm(t('confirmDropColumn', { name: name }))) return;
        api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '/columns/' + encodeURIComponent(name), { method: 'DELETE' })
          .then(function () { setStatus(t('columnDropped'), true); renderBody(); })
          .catch(function (error) { setStatus(error.message, false); });
      });
    });
    bodyEl.querySelectorAll('[data-edit]').forEach(function (button) {
      button.addEventListener('click', function () {
        var column = data.columns.filter(function (item) { return item.name === button.getAttribute('data-edit'); })[0];
        var form = document.getElementById('pma-column');
        form.querySelector('[name="name"]').value = column.name;
        form.setAttribute('data-old', column.name);
        form.querySelector('button').textContent = t('changeColumn');
      });
    });
    document.getElementById('pma-column').addEventListener('submit', function (event) {
      event.preventDefault();
      var form = event.target;
      var payload = {
        name: form.querySelector('[name="name"]').value.trim(),
        type: form.querySelector('[name="type"]').value,
        length: form.querySelector('[name="length"]').value,
        scale: form.querySelector('[name="scale"]').value,
        nullable: form.querySelector('[name="nullable"]').checked
      };
      var oldName = form.getAttribute('data-old');
      var path = '/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '/columns';
      var method = 'POST';
      if (oldName) {
        path += '/' + encodeURIComponent(oldName);
        method = 'PATCH';
      }
      api(path, { method: method, body: JSON.stringify(payload) }).then(function () {
        setStatus(t('columnSaved'), true);
        renderBody();
      }).catch(function (error) { setStatus(error.message, false); });
    });
  }

  function shown(value) {
    if (value && typeof value === 'object' && value.binary) return '[binary]';
    if (value == null) return 'NULL';
    return String(value);
  }

  function formatCell(value) {
    if (value && typeof value === 'object' && value.binary) return '<span class="pma-bin">[binary]</span>';
    if (value == null) return '<span class="pma-null">NULL</span>';
    return esc(value);
  }

  function cellInput(column, value) {
    var name = esc(column.name);
    if (value && typeof value === 'object' && value.binary) {
      return '<div class="pma-edit"><input class="pma-input" value="[binary]" disabled data-binary="1" data-col="' + name + '"></div>';
    }
    var text = value == null ? '' : String(value);
    var field = /text|json/i.test(column.data_type)
      ? '<textarea class="pma-input" data-col="' + name + '"' + (value == null ? ' disabled' : '') + '>' + esc(text) + '</textarea>'
      : '<input class="pma-input" type="text" data-col="' + name + '" value="' + esc(text) + '"' + (value == null ? ' disabled' : '') + '>';
    return '<div class="pma-edit">' + field + '<label class="pma-nullbox"><input type="checkbox" data-null="1"' + (value == null ? ' checked' : '') + '> NULL</label></div>';
  }

  function readRow(tr, columns) {
    var values = {};
    columns.forEach(function (column) {
      var field = tr.querySelector('[data-col="' + CSS.escape(column.name) + '"]');
      if (!field || field.getAttribute('data-binary')) {
        values[column.name] = { binary: true };
        return;
      }
      var nullBox = field.parentElement.querySelector('[data-null]');
      values[column.name] = nullBox && nullBox.checked ? null : field.value;
    });
    return values;
  }

  function rowKeys(data, row) {
    var keys = {};
    data.primary.forEach(function (name) { keys[name] = row[name]; });
    return keys;
  }

  function renderBrowse(data) {
    var pages = Math.max(1, Math.ceil(data.total / data.per_page));
    var from = data.total ? ((data.page - 1) * data.per_page) + 1 : 0;
    var to = Math.min(data.total, data.page * data.per_page);
    var html = '<div class="pma-notice">' + esc(t('shown', { from: from, to: to, total: data.total })) + '</div>';
    html += '<div class="pma-bar">' +
      '<button type="button" id="pma-prev" class="pma-btn"' + (data.page <= 1 ? ' disabled' : '') + '>' + esc(t('prev')) + '</button>' +
      '<span class="pma-bar-meta">' + esc(t('page', { page: data.page, pages: pages })) + '</span>' +
      '<button type="button" id="pma-next" class="pma-btn"' + (data.page >= pages ? ' disabled' : '') + '>' + esc(t('next')) + '</button>' +
      '<button type="button" id="pma-add" class="pma-btn">' + ico('insert') + esc(t('newRow')) + '</button>' +
      '<button type="button" id="pma-delete-selected" class="pma-btn danger" disabled>' + ico('trash') + esc(t('deleteSelected')) + '</button>' +
      '</div>';
    html += '<div class="pma-scroll"><table class="pma-table pma-grid"><thead><tr><th class="pma-check"><input type="checkbox" id="pma-all"></th><th class="pma-ops">' + esc(t('action')) + '</th>' +
      data.columns.map(function (column) {
        return '<th>' + esc(column.name) + (column.key === 'PRI' ? ' ' + ico('key') : '') + '</th>';
      }).join('') + '</tr></thead><tbody>';
    if (!data.rows.length) html += '<tr><td colspan="' + (data.columns.length + 2) + '">' + esc(t('noRows')) + '</td></tr>';
    data.rows.forEach(function (row, index) {
      html += '<tr data-i="' + index + '"><td class="pma-check"><input type="checkbox" class="pma-pick"></td><td class="pma-ops">' +
        act('edit', t('edit'), 'data-do="edit"') +
        act('copy', t('copy'), 'data-do="copy"') +
        act('trash', t('delete'), 'data-do="delete"', true) +
        '</td>' + data.columns.map(function (column) {
          return '<td class="pma-val" title="' + esc(shown(row[column.name])) + '">' + formatCell(row[column.name]) + '</td>';
        }).join('') + '</tr>';
    });
    html += '</tbody></table></div>';
    bodyEl.innerHTML = html;

    function refreshDelete() {
      var button = document.getElementById('pma-delete-selected');
      button.disabled = !bodyEl.querySelector('.pma-pick:checked');
    }

    bodyEl.querySelector('tbody').addEventListener('click', function (event) {
      var button = event.target.closest('[data-do]');
      if (!button) return;
      var tr = button.closest('tr');
      var row = data.rows[Number(tr.getAttribute('data-i'))];
      var action = button.getAttribute('data-do');
      if (action === 'edit') {
        tr.classList.add('is-editing');
        tr.innerHTML = '<td class="pma-check"></td><td class="pma-ops"><button type="button" class="pma-btn primary" data-do="save">' + esc(t('save')) + '</button> <button type="button" class="pma-btn" data-do="cancel">' + esc(t('cancel')) + '</button></td>' +
          data.columns.map(function (column) { return '<td>' + cellInput(column, row[column.name]) + '</td>'; }).join('');
        return;
      }
      if (action === 'cancel') {
        renderBody();
        return;
      }
      if (action === 'save') {
        api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '/rows', {
          method: 'PUT',
          body: JSON.stringify({ keys: rowKeys(data, row), values: readRow(tr, data.columns) })
        }).then(function () { setStatus(t('rowSaved'), true); renderBody(); })
          .catch(function (error) { setStatus(error.message, false); });
        return;
      }
      if (action === 'copy') {
        var values = {};
        data.columns.forEach(function (column) {
          if (column.extra && column.extra.indexOf('auto_increment') !== -1) return;
          var value = row[column.name];
          values[column.name] = value && value.binary ? null : value;
        });
        api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '/rows', {
          method: 'POST',
          body: JSON.stringify({ values: values })
        }).then(function () { setStatus(t('rowCopied'), true); renderBody(); })
          .catch(function (error) { setStatus(error.message, false); });
        return;
      }
      if (action === 'delete') {
        if (!window.confirm(t('confirmDeleteRow'))) return;
        api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '/rows', {
          method: 'DELETE',
          body: JSON.stringify({ keys: rowKeys(data, row) })
        }).then(function () { setStatus(t('rowDeleted'), true); renderBody(); })
          .catch(function (error) { setStatus(error.message, false); });
      }
    });
    bodyEl.querySelector('tbody').addEventListener('change', function (event) {
      if (event.target.classList.contains('pma-pick')) {
        refreshDelete();
        return;
      }
      if (!event.target.hasAttribute('data-null')) return;
      var field = event.target.closest('.pma-edit').querySelector('[data-col]');
      if (field) field.disabled = event.target.checked;
    });
    document.getElementById('pma-all').addEventListener('change', function () {
      var checked = this.checked;
      bodyEl.querySelectorAll('.pma-pick').forEach(function (box) { box.checked = checked; });
      refreshDelete();
    });
    document.getElementById('pma-prev').addEventListener('click', function () { if (data.page > 1) { state.page -= 1; renderBody(); } });
    document.getElementById('pma-next').addEventListener('click', function () { if (data.page < pages) { state.page += 1; renderBody(); } });
    document.getElementById('pma-add').addEventListener('click', function () { state.tab = 'insert'; renderBody(); });
    document.getElementById('pma-delete-selected').addEventListener('click', function () {
      var picked = [];
      bodyEl.querySelectorAll('.pma-pick:checked').forEach(function (box) {
        var tr = box.closest('tr');
        picked.push(data.rows[Number(tr.getAttribute('data-i'))]);
      });
      if (!picked.length || !window.confirm(t('confirmDeleteRows', { n: picked.length }))) return;
      var chain = Promise.resolve();
      picked.forEach(function (row) {
        chain = chain.then(function () {
          return api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '/rows', {
            method: 'DELETE',
            body: JSON.stringify({ keys: rowKeys(data, row) })
          });
        });
      });
      chain.then(function () { setStatus(t('rowsDeleted', { n: picked.length }), true); renderBody(); })
        .catch(function (error) { setStatus(error.message, false); });
    });
  }

  function renderInsert(data) {
    var html = '<form id="pma-insert"><div class="pma-scroll"><table class="pma-table"><thead><tr><th>' + esc(t('column')) + '</th><th>' + esc(t('type')) + '</th><th>' + esc(t('value')) + '</th><th>' + esc(t('nullable')) + '</th></tr></thead><tbody>';
    data.columns.forEach(function (column) {
      var auto = column.extra.indexOf('auto_increment') !== -1;
      html += '<tr><td>' + esc(column.name) + (column.key === 'PRI' ? ' <span class="pma-key">PK</span>' : '') + '</td><td class="pma-muted">' + esc(column.column_type) + '</td><td>' +
        '<input class="pma-input" type="text" data-col="' + esc(column.name) + '"' + (auto ? ' placeholder="' + esc(t('auto')) + '"' : '') + (column.nullable ? ' disabled' : '') + '>' +
        '</td><td class="pma-check"><input type="checkbox" data-null="1"' + (column.nullable ? ' checked' : '') + '></td></tr>';
    });
    html += '</tbody></table></div><div class="pma-bar"><button class="pma-btn primary" type="submit">' + ico('insert') + esc(t('add')) + '</button></div></form>';
    bodyEl.innerHTML = html;
    document.getElementById('pma-insert').addEventListener('change', function (event) {
      if (!event.target.hasAttribute('data-null')) return;
      var field = event.target.closest('tr').querySelector('[data-col]');
      if (field) field.disabled = event.target.checked;
    });
    document.getElementById('pma-insert').addEventListener('submit', function (event) {
      event.preventDefault();
      var values = {};
      data.columns.forEach(function (column) {
        var field = event.target.querySelector('[data-col="' + CSS.escape(column.name) + '"]');
        var nullBox = field.closest('tr').querySelector('[data-null]');
        if (nullBox && nullBox.checked && field.value === '') values[column.name] = null;
        else values[column.name] = field.value;
      });
      api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(state.table) + '/rows', {
        method: 'POST',
        body: JSON.stringify({ values: values })
      }).then(function () { setStatus(t('rowAdded'), true); state.tab = 'browse'; renderBody(); })
        .catch(function (error) { setStatus(error.message, false); });
    });
  }

  function renderSql() {
    bodyEl.innerHTML = '<textarea id="pma-sql" class="pma-sql" placeholder="' + esc(t('sqlPlaceholder')) + '"></textarea><div class="pma-bar"><button type="button" id="pma-run" class="pma-btn primary">' + esc(t('go')) + '</button></div><div id="pma-sql-out"></div>';
    document.getElementById('pma-run').addEventListener('click', function () {
      api('/databases/' + encodeURIComponent(state.db) + '/sql', {
        method: 'POST',
        body: JSON.stringify({ sql: document.getElementById('pma-sql').value })
      }).then(function (data) {
        var html = '';
        data.results.forEach(function (result) {
          if (result.rows) {
            html += '<div class="pma-notice">' + esc(t('shown', { from: 0, to: Math.max(0, result.rows.length - 1), total: result.rows.length })) + '</div><div class="pma-scroll"><table class="pma-table"><thead><tr>' + result.columns.map(function (column) { return '<th>' + esc(column) + '</th>'; }).join('') + '</tr></thead><tbody>';
            result.rows.forEach(function (row) {
              html += '<tr>' + result.columns.map(function (column) {
                var value = row[column];
                return '<td class="pma-val" title="' + esc(shown(value)) + '">' + formatCell(value) + '</td>';
              }).join('') + '</tr>';
            });
            html += '</tbody></table></div>';
          } else {
            html += '<p>' + esc(t('affected', { n: result.affected })) + '</p>';
          }
        });
        document.getElementById('pma-sql-out').innerHTML = html || '<p>' + esc(t('queryOk')) + '</p>';
        setStatus('', true);
      }).catch(function (error) { setStatus(error.message, false); });
    });
  }

  function renderSearch() {
    var options = '<option value="">' + esc(t('allColumns')) + '</option>';
    bodyEl.innerHTML = '<form id="pma-search" class="pma-bar">' +
      '<label>' + esc(t('table')) + ' <select name="table">' + state.tables.map(function (table) {
        return '<option' + (table.name === state.table ? ' selected' : '') + '>' + esc(table.name) + '</option>';
      }).join('') + '</select></label>' +
      '<label>' + esc(t('column')) + ' <select name="column">' + options + '</select></label>' +
      '<label>' + esc(t('operator')) + ' <select name="op"><option value="like">LIKE %...%</option><option value="eq">=</option><option value="ne">&lt;&gt;</option><option value="gt">&gt;</option><option value="lt">&lt;</option></select></label>' +
      '<label>' + esc(t('valueLabel')) + ' <input name="q" type="text"></label>' +
      '<button class="pma-btn primary" type="submit">' + ico('search') + esc(t('search')) + '</button></form><div id="pma-search-out"></div>';
    document.getElementById('pma-search').addEventListener('submit', function (event) {
      event.preventDefault();
      var form = event.target;
      var table = form.querySelector('[name="table"]').value;
      var params = new URLSearchParams({
        column: form.querySelector('[name="column"]').value,
        op: form.querySelector('[name="op"]').value,
        q: form.querySelector('[name="q"]').value,
        page: '1'
      });
      state.table = table;
      api('/databases/' + encodeURIComponent(state.db) + '/tables/' + encodeURIComponent(table) + '/search?' + params.toString()).then(function (data) {
        var html = '<div class="pma-notice">' + esc(t('results', { n: data.total })) + '</div><div class="pma-scroll"><table class="pma-table"><thead><tr>' + data.columns.map(function (column) { return '<th>' + esc(column.name) + '</th>'; }).join('') + '</tr></thead><tbody>';
        data.rows.forEach(function (row) {
          html += '<tr>' + data.columns.map(function (column) {
            var value = row[column.name];
            return '<td class="pma-val" title="' + esc(shown(value)) + '">' + formatCell(value) + '</td>';
          }).join('') + '</tr>';
        });
        document.getElementById('pma-search-out').innerHTML = html + '</tbody></table></div>';
        renderChrome();
        renderTree();
      }).catch(function (error) { setStatus(error.message, false); });
    });
  }

  function renderExport() {
    var html = '<form id="pma-export"><p class="pma-muted">' + esc(t('exportHelp')) + '</p><div class="pma-checks">';
    state.tables.forEach(function (table) {
      var checked = !state.table || state.table === table.name;
      html += '<label><input type="checkbox" value="' + esc(table.name) + '"' + (checked ? ' checked' : '') + '> ' + ico('table') + esc(table.name) + '</label>';
    });
    html += '</div><div class="pma-bar"><button class="pma-btn primary" type="submit">' + ico('export') + esc(t('export')) + '</button></div></form>';
    bodyEl.innerHTML = html;
    document.getElementById('pma-export').addEventListener('submit', function (event) {
      event.preventDefault();
      var names = Array.prototype.map.call(event.target.querySelectorAll('input:checked'), function (input) { return input.value; });
      var token = document.querySelector('meta[name="_token"]').getAttribute('content');
      fetch('/extensions/petodb/hosts/' + hostId + '/databases/' + encodeURIComponent(state.db) + '/export?tables=' + encodeURIComponent(names.join(',')), {
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'X-PetoDB-Lang': state.lang }
      }).then(function (response) {
        if (!response.ok) return response.json().then(function (data) { throw new Error(data.message || t('exportFail')); });
        return response.blob();
      }).then(function (blob) {
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = state.db + '.sql';
        link.click();
      }).catch(function (error) { setStatus(error.message, false); });
    });
  }

  function renderImport() {
    bodyEl.innerHTML = '<form id="pma-import">' +
      '<p class="pma-muted">' + esc(t('importHelp', { db: state.db })).replace(esc(state.db), '<strong>' + esc(state.db) + '</strong>') + '</p>' +
      '<div class="pma-bar"><label class="pma-btn">' + esc(t('chooseFile')) + '<input id="pma-file" type="file" accept=".sql,.txt,text/plain" hidden></label>' +
      '<span id="pma-file-name">' + esc(t('noFile')) + '</span></div>' +
      '<textarea id="pma-import-sql" class="pma-sql" placeholder="' + esc(t('pasteSql')) + '"></textarea>' +
      '<div class="pma-bar"><button class="pma-btn primary" type="submit">' + ico('import') + esc(t('import')) + '</button></div></form>';
    document.getElementById('pma-file').addEventListener('change', function () {
      var file = this.files && this.files[0];
      var label = document.getElementById('pma-file-name');
      if (!file) return;
      if (file.size > 8000000) {
        setStatus(t('fileTooBig'), false);
        this.value = '';
        return;
      }
      label.textContent = ' ' + file.name + ' (' + fmtSize(file.size) + ')';
      var reader = new FileReader();
      reader.onload = function () { document.getElementById('pma-import-sql').value = String(reader.result || ''); };
      reader.readAsText(file);
    });
    document.getElementById('pma-import').addEventListener('submit', function (event) {
      event.preventDefault();
      var sql = document.getElementById('pma-import-sql').value;
      if (!sql.trim()) {
        setStatus(t('importNeed'), false);
        return;
      }
      setStatus(t('importing'), true);
      api('/databases/' + encodeURIComponent(state.db) + '/import', {
        method: 'POST',
        body: JSON.stringify({ sql: sql })
      }).then(function (data) {
        var message = t('imported', { n: data.statements, db: data.database });
        if (data.skipped) message += ' ' + t('skipped', { n: data.skipped });
        setStatus(message, true);
        return api('/databases/' + encodeURIComponent(state.db) + '/tables');
      }).then(function (data) {
        if (!data) return;
        state.tables = data.tables || [];
        state.table = null;
        state.tab = 'structure';
        renderTree();
        renderBody();
      }).catch(function (error) { setStatus(error.message, false, error.sql || ''); });
    });
  }

  function renderOperations() {
    var html = '<h4 class="pma-legend">' + esc(t('newTable')) + '</h4><form id="pma-create" class="pma-bar"><input name="name" type="text" placeholder="' + esc(t('tablePlaceholder')) + '" required><button class="pma-btn primary" type="submit">' + esc(t('create')) + '</button></form>';
    if (state.table) {
      html += '<h4 class="pma-legend">' + esc(state.table) + '</h4><div class="pma-bar">' +
        '<button type="button" id="pma-empty" class="pma-btn warn">' + ico('empty') + esc(t('emptyTable')) + '</button>' +
        '<button type="button" id="pma-drop" class="pma-btn danger">' + ico('trash') + esc(t('dropTable')) + '</button>' +
        '<button type="button" id="pma-optimize" class="pma-btn">' + ico('operations') + esc(t('optimize')) + '</button></div>';
    }
    bodyEl.innerHTML = html;
    document.getElementById('pma-create').addEventListener('submit', function (event) {
      event.preventDefault();
      var name = event.target.querySelector('[name="name"]').value.trim();
      api('/databases/' + encodeURIComponent(state.db) + '/tables', { method: 'POST', body: JSON.stringify({ name: name }) })
        .then(function () { setStatus(t('tableCreated'), true); selectDb(state.db); })
        .catch(function (error) { setStatus(error.message, false); });
    });
    var empty = document.getElementById('pma-empty');
    var drop = document.getElementById('pma-drop');
    var optimize = document.getElementById('pma-optimize');
    if (empty) empty.addEventListener('click', function () { emptyTable(state.table); });
    if (drop) drop.addEventListener('click', function () { dropTable(state.table); });
    if (optimize) optimize.addEventListener('click', function () {
      api('/databases/' + encodeURIComponent(state.db) + '/sql', {
        method: 'POST',
        body: JSON.stringify({ sql: 'OPTIMIZE TABLE `' + state.table + '`' })
      }).then(function () { setStatus(t('optimized'), true); }).catch(function (error) { setStatus(error.message, false); });
    });
  }

  function renderSettings() {
    var codes = [['tr', 'Türkçe'], ['en', 'English'], ['de', 'Deutsch'], ['fr', 'Français'], ['ru', 'Русский']];
    var html = '<h4 class="pma-legend">' + esc(t('language')) + '</h4><p class="pma-muted">' + esc(t('languageHelp')) + '</p><div class="pma-lang">';
    codes.forEach(function (item) {
      html += '<button type="button" data-lang="' + item[0] + '" class="' + (state.lang === item[0] ? 'is-active' : '') + '">' + esc(item[1]) + '</button>';
    });
    bodyEl.innerHTML = html + '</div>';
    bodyEl.querySelectorAll('[data-lang]').forEach(function (button) {
      button.addEventListener('click', function () {
        state.lang = button.getAttribute('data-lang');
        try { localStorage.setItem('petodb.lang', state.lang); } catch (error) {}
        applyStatic();
        renderBody();
        setStatus(t('saved'), true);
      });
    });
  }

  document.getElementById('pma-filter').addEventListener('input', function () {
    state.filter = this.value;
    renderTree();
  });

  applyStatic();
  loadDatabases();
})();
</script>
@endverbatim
