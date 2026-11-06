<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\QueryException;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Models\Database;

if (!function_exists('petodb_fail')) {
    function petodb_fail(string $message, int $status = 422, array $extra = [])
    {
        return response()->json(array_merge(['message' => $message], $extra), $status);
    }

    function petodb_mask_sql_strings(string $sql): string
    {
        $out = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char !== "'" && $char !== '"') {
                $out .= $char;
                continue;
            }
            $quote = $char;
            $i++;
            while ($i < $length) {
                if ($sql[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                if ($sql[$i] === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $i += 2;
                        continue;
                    }
                    break;
                }
                $i++;
            }
            $out .= "''";
        }

        return $out;
    }

    function petodb_blocked_schema(string $sql): ?string
    {
        if (!preg_match('/(^|[^A-Za-z0-9_])`?(information_schema|performance_schema|mysql|sys|panel)`?\s*\./i', $sql, $match)) {
            return null;
        }

        return strtolower($match[2]);
    }

    function petodb_excerpt(string $sql): string
    {
        $sql = trim($sql);
        if (strlen($sql) <= 1500) {
            return $sql;
        }

        return substr($sql, 0, 1500) . "\n…";
    }

    function petodb_import_stop(int $index, int $total, int $done, string $reason, string $sql)
    {
        $message = petodb_t('import_failed', ['index' => (string) $index, 'total' => (string) $total]);
        if ($done > 0) {
            $message .= ' ' . petodb_t('import_partial', ['done' => (string) $done]);
        }
        $message .= ' ' . petodb_maybe($reason);

        return petodb_fail($message, 422, [
            'sql' => petodb_excerpt($sql),
            'index' => $index,
            'done' => $done,
        ]);
    }

    function petodb_ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new InvalidArgumentException('invalid_name');
        }

        return '`' . $name . '`';
    }

    function petodb_open(int $hostId, string $schema): array
    {
        petodb_ident($schema);
        if (in_array(strtolower($schema), ['information_schema', 'mysql', 'performance_schema', 'sys', 'panel'], true)) {
            throw new InvalidArgumentException('db_locked');
        }

        $host = \Pterodactyl\Models\DatabaseHost::query()->find($hostId);
        if (!$host) {
            throw new RuntimeException('host_missing');
        }

        DB::purge('dynamic');
        app(DynamicDatabaseConnection::class)->set('dynamic', $host, $schema);
        $connection = DB::connection('dynamic');
        $found = $connection->select(
            'SELECT 1 AS ok FROM information_schema.schemata WHERE schema_name = ? LIMIT 1',
            [$schema]
        );
        if ($found === []) {
            throw new InvalidArgumentException('db_missing');
        }

        $known = Database::query()->with('server')->where('database', $schema)->first();

        return [$connection, $schema, [
            'id' => $schema,
            'name' => $schema,
            'server' => $known?->server->name ?? '',
            'host' => $host->name,
            'username' => $known?->username ?? $host->username,
        ]];
    }

    function petodb_columns($connection, string $schema, string $table): array
    {
        $rows = $connection->select(
            'SELECT column_name AS name, data_type AS data_type, column_type AS column_type, is_nullable AS is_nullable, column_key AS column_key, extra AS extra, column_default AS column_default, collation_name AS collation, column_comment AS comment, character_maximum_length AS char_length, numeric_precision AS num_precision, numeric_scale AS num_scale
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ?
             ORDER BY ordinal_position',
            [$schema, $table]
        );

        return array_map(static function ($row) {
            $row = (array) $row;

            return [
                'name' => (string) $row['name'],
                'data_type' => (string) $row['data_type'],
                'column_type' => (string) $row['column_type'],
                'nullable' => strtoupper((string) $row['is_nullable']) === 'YES',
                'key' => (string) $row['column_key'],
                'extra' => (string) $row['extra'],
                'default' => $row['column_default'],
                'collation' => (string) ($row['collation'] ?? ''),
                'comment' => (string) ($row['comment'] ?? ''),
                'length' => $row['char_length'] !== null ? (int) $row['char_length'] : ($row['num_precision'] !== null ? (int) $row['num_precision'] : null),
                'scale' => $row['num_scale'] !== null ? (int) $row['num_scale'] : null,
            ];
        }, $rows);
    }

    function petodb_assert_table($connection, string $schema, string $table): void
    {
        petodb_ident($table);
        $found = $connection->select(
            'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = ? AND table_name = ? AND table_type = ? LIMIT 1',
            [$schema, $table, 'BASE TABLE']
        );
        if ($found === []) {
            throw new InvalidArgumentException('table_missing');
        }
    }

    function petodb_type_sql(string $type, $length, $scale): string
    {
        $type = strtolower($type);
        $plain = [
            'int' => 'INT',
            'bigint' => 'BIGINT',
            'tinyint' => 'TINYINT',
            'text' => 'TEXT',
            'mediumtext' => 'MEDIUMTEXT',
            'longtext' => 'LONGTEXT',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'date' => 'DATE',
            'time' => 'TIME',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'json' => 'JSON',
            'boolean' => 'TINYINT(1)',
        ];
        if (isset($plain[$type])) {
            return $plain[$type];
        }
        if ($type === 'varchar') {
            $size = (int) $length;
            if ($size < 1 || $size > 16383) {
                throw new InvalidArgumentException('varchar_length');
            }

            return 'VARCHAR(' . $size . ')';
        }
        if ($type === 'decimal') {
            $precision = (int) $length;
            $scale = (int) $scale;
            if ($precision < 1 || $precision > 65 || $scale < 0 || $scale > $precision) {
                throw new InvalidArgumentException('decimal_invalid');
            }

            return 'DECIMAL(' . $precision . ',' . $scale . ')';
        }

        throw new InvalidArgumentException('type_unsupported');
    }

    function petodb_cell($value)
    {
        if (!is_string($value) || preg_match('//u', $value) === 1) {
            return $value;
        }

        return ['binary' => true];
    }

    function petodb_assignments(array $input, array $columns, bool $skipBlankAuto): array
    {
        $known = [];
        foreach ($columns as $column) {
            $known[$column['name']] = $column;
        }

        $sql = [];
        $bindings = [];
        foreach ($input as $name => $value) {
            $name = (string) $name;
            if (!isset($known[$name])) {
                throw new InvalidArgumentException('unknown_column');
            }
            if (is_array($value)) {
                continue;
            }
            $auto = str_contains((string) $known[$name]['extra'], 'auto_increment');
            if ($skipBlankAuto && $auto && ($value === null || $value === '')) {
                continue;
            }
            $sql[] = petodb_ident($name) . ' = ?';
            $bindings[] = $value;
        }
        if ($sql === []) {
            throw new InvalidArgumentException('no_values');
        }

        return [$sql, $bindings];
    }

    function petodb_where(array $keys, array $primary): array
    {
        if ($primary === []) {
            throw new InvalidArgumentException('no_primary');
        }

        $sql = [];
        $bindings = [];
        foreach ($primary as $column) {
            if (!array_key_exists($column, $keys)) {
                throw new InvalidArgumentException('primary_missing');
            }
            $sql[] = petodb_ident($column) . ' <=> ?';
            $bindings[] = $keys[$column];
        }

        return [implode(' AND ', $sql), $bindings];
    }

    function petodb_primary(array $columns): array
    {
        $primary = [];
        foreach ($columns as $column) {
            if ($column['key'] === 'PRI') {
                $primary[] = $column['name'];
            }
        }

        return $primary;
    }

    function petodb_split_sql(string $sql): array
    {
        $parts = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $delimiter = ';';
        for ($i = 0; $i < $length; $i++) {
            if ($quote === null && trim($buffer) === '' && preg_match('/\G\s*DELIMITER\s+(\S+)/iA', $sql, $match, 0, $i)) {
                $delimiter = $match[1];
                $i += strlen($match[0]) - 1;
                continue;
            }
            $char = $sql[$i];
            if ($quote) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $buffer .= $sql[++$i];
                    continue;
                }
                if ($char === $quote) {
                    if ($quote !== '`' && $i + 1 < $length && $sql[$i + 1] === $quote) {
                        $buffer .= $sql[++$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $i += 2;
                if ($i < $length && $sql[$i] === '!') {
                    $i++;
                    while ($i < $length && ctype_digit($sql[$i])) {
                        $i++;
                    }
                    while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                        $buffer .= $sql[$i];
                        $i++;
                    }
                } else {
                    while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                        $i++;
                    }
                }
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                if (trim($buffer) !== '') {
                    $parts[] = trim($buffer);
                }
                $buffer = '';
                $i += strlen($delimiter) - 1;
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    function petodb_prepare_import(string $statement): ?string
    {
        $statement = trim($statement);
        if ($statement === '') {
            return null;
        }
        $head = strtoupper(ltrim($statement));
        $skip = ['SET', 'USE', 'START', 'COMMIT', 'ROLLBACK', 'LOCK', 'UNLOCK', 'CREATE DATABASE', 'DROP DATABASE', 'DELIMITER'];
        foreach ($skip as $prefix) {
            if ($head === $prefix || str_starts_with($head, $prefix . ' ') || str_starts_with($head, $prefix . "\n") || str_starts_with($head, $prefix . "\t")) {
                return null;
            }
        }
        $visible = petodb_mask_sql_strings($statement);
        if (preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE|LOAD\s+DATA|GRANT|REVOKE|CREATE\s+USER|DROP\s+USER|SHUTDOWN)\b/i', $visible)) {
            throw new InvalidArgumentException('query_forbidden');
        }
        $blocked = petodb_blocked_schema($visible);
        if ($blocked !== null) {
            throw new InvalidArgumentException(petodb_t('system_schema', ['name' => $blocked]));
        }

        return $statement;
    }

    function petodb_assert_sql(string $statement): void
    {
        $head = strtoupper(ltrim($statement));
        $allowed = ['SELECT', 'SHOW', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'ALTER', 'CREATE', 'DROP', 'TRUNCATE', 'DESCRIBE', 'DESC', 'EXPLAIN', 'RENAME', 'OPTIMIZE', 'REPAIR', 'ANALYZE', 'CHECK'];
        $ok = false;
        foreach ($allowed as $word) {
            if ($head === $word || str_starts_with($head, $word . ' ') || str_starts_with($head, $word . "\n") || str_starts_with($head, $word . "\t") || str_starts_with($head, $word . '(')) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new InvalidArgumentException('query_type');
        }
        $visible = petodb_mask_sql_strings($statement);
        if (preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE|LOAD\s+DATA|GRANT|REVOKE|CREATE\s+USER|DROP\s+USER|CREATE\s+DATABASE|DROP\s+DATABASE|SHUTDOWN)\b/i', $visible)) {
            throw new InvalidArgumentException('query_denied');
        }
        $blocked = petodb_blocked_schema($visible);
        if ($blocked !== null) {
            throw new InvalidArgumentException(petodb_t('system_schema', ['name' => $blocked]));
        }
    }

    function petodb_sql_value($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('//u', $value) !== 1) {
            return '0x' . bin2hex($value);
        }

        return "'" . str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], (string) $value) . "'";
    }

    function petodb_lang(): string
    {
        $lang = strtolower(substr((string) request()->header('X-PetoDB-Lang', 'tr'), 0, 2));

        return in_array($lang, ['tr', 'en', 'de', 'fr', 'ru'], true) ? $lang : 'tr';
    }

    function petodb_dict(string $lang): array
    {
        $all = [
            'tr' => [
                'invalid_name' => 'Geçersiz isim.',
                'db_locked' => 'Bu veritabanı açılamaz.',
                'forbidden' => 'Bu sunucunun veritabanına erişilemez.',
                'host_missing' => 'Host bulunamadı.',
                'db_missing' => 'Veritabanı bulunamadı.',
                'table_missing' => 'Tablo bulunamadı.',
                'varchar_length' => 'VARCHAR uzunluğu 1 ile 16383 arasında olmalı.',
                'decimal_invalid' => 'DECIMAL precision veya scale geçersiz.',
                'type_unsupported' => 'Desteklenmeyen sütun tipi.',
                'unknown_column' => 'Bilinmeyen sütun.',
                'no_values' => 'Kaydedilecek değer yok.',
                'no_primary' => 'Bu tabloda primary key yok. Satır düzenlemek için bir primary key sütunu ekle.',
                'primary_missing' => 'Primary key eksik.',
                'query_forbidden' => 'Dosyada çalıştırılamayan bir sorgu var.',
                'system_schema' => 'Sistem veritabanına erişiliyor: !{name}.',
                'query_type' => 'Bu sorgu tipine izin yok.',
                'query_denied' => 'Bu sorguya izin yok.',
                'bad_row' => 'Satır değerleri geçersiz.',
                'row_missing' => 'Satır bulunamadı.',
                'column_missing' => 'Sütun bulunamadı.',
                'last_column' => 'Tablodaki son sütun silinemez.',
                'bad_operator' => 'Geçersiz operatör.',
                'sql_count' => '1 ile 20 sorgu arasında gönder.',
                'sql_empty' => 'SQL dosyası boş.',
                'sql_size' => 'SQL 8 MB sınırını aşıyor.',
                'sql_none' => 'Çalıştırılacak sorgu bulunamadı.',
                'sql_too_many' => 'Dosyada 10000 sorgudan fazla var. Daha küçük parçalara böl.',
                'sql_no_data' => 'Dosyada bu veritabanına yazılacak tablo veya satır yok.',
                'query_failed' => 'Sorgu çalışmadı.',
                'connect_failed' => 'Veritabanına bağlanılamadı.',
                'import_failed' => 'Sorgu {index} / {total} çalışmadı.',
                'import_partial' => 'Ondan önce {done} sorgu yazıldı.',
            ],
            'en' => [
                'invalid_name' => 'Invalid name.',
                'db_locked' => 'This database cannot be opened.',
                'forbidden' => 'You cannot access this server database.',
                'host_missing' => 'Host not found.',
                'db_missing' => 'Database not found.',
                'table_missing' => 'Table not found.',
                'varchar_length' => 'VARCHAR length must be between 1 and 16383.',
                'decimal_invalid' => 'DECIMAL precision or scale is invalid.',
                'type_unsupported' => 'Unsupported column type.',
                'unknown_column' => 'Unknown column.',
                'no_values' => 'There is nothing to save.',
                'no_primary' => 'This table has no primary key. Add a primary key column to edit rows.',
                'primary_missing' => 'Primary key is missing.',
                'query_forbidden' => 'The file contains a statement that cannot be run.',
                'system_schema' => 'Access to system database: !{name}.',
                'query_type' => 'This statement type is not allowed.',
                'query_denied' => 'This statement is not allowed.',
                'bad_row' => 'Row values are invalid.',
                'row_missing' => 'Row not found.',
                'column_missing' => 'Column not found.',
                'last_column' => 'The last column of the table cannot be dropped.',
                'bad_operator' => 'Invalid operator.',
                'sql_count' => 'Send between 1 and 20 statements.',
                'sql_empty' => 'The SQL file is empty.',
                'sql_size' => 'SQL exceeds the 8 MB limit.',
                'sql_none' => 'No statement to run was found.',
                'sql_too_many' => 'The file has more than 10000 statements. Split it into smaller parts.',
                'sql_no_data' => 'The file has no table or row to write into this database.',
                'query_failed' => 'The query failed.',
                'connect_failed' => 'Could not connect to the database.',
                'import_failed' => 'Statement {index} / {total} failed.',
                'import_partial' => '{done} statements were written before it stopped.',
            ],
            'de' => [
                'invalid_name' => 'Ungültiger Name.',
                'db_locked' => 'Diese Datenbank kann nicht geöffnet werden.',
                'forbidden' => 'Kein Zugriff auf die Datenbank dieses Servers.',
                'host_missing' => 'Host nicht gefunden.',
                'db_missing' => 'Datenbank nicht gefunden.',
                'table_missing' => 'Tabelle nicht gefunden.',
                'varchar_length' => 'Die VARCHAR-Länge muss zwischen 1 und 16383 liegen.',
                'decimal_invalid' => 'DECIMAL-Präzision oder Skala ist ungültig.',
                'type_unsupported' => 'Nicht unterstützter Spaltentyp.',
                'unknown_column' => 'Unbekannte Spalte.',
                'no_values' => 'Nichts zu speichern.',
                'no_primary' => 'Diese Tabelle hat keinen Primärschlüssel. Fügen Sie eine Primärschlüsselspalte hinzu, um Zeilen zu bearbeiten.',
                'primary_missing' => 'Primärschlüssel fehlt.',
                'query_forbidden' => 'Die Datei enthält eine Anweisung, die nicht ausgeführt werden kann.',
                'system_schema' => 'Zugriff auf Systemdatenbank: !{name}.',
                'query_type' => 'Dieser Anweisungstyp ist nicht erlaubt.',
                'query_denied' => 'Diese Anweisung ist nicht erlaubt.',
                'bad_row' => 'Zeilenwerte sind ungültig.',
                'row_missing' => 'Zeile nicht gefunden.',
                'column_missing' => 'Spalte nicht gefunden.',
                'last_column' => 'Die letzte Spalte der Tabelle kann nicht gelöscht werden.',
                'bad_operator' => 'Ungültiger Operator.',
                'sql_count' => 'Senden Sie zwischen 1 und 20 Anweisungen.',
                'sql_empty' => 'Die SQL-Datei ist leer.',
                'sql_size' => 'SQL überschreitet die Grenze von 8 MB.',
                'sql_none' => 'Keine ausführbare Anweisung gefunden.',
                'sql_too_many' => 'Die Datei enthält mehr als 10000 Anweisungen. Teilen Sie sie in kleinere Teile.',
                'sql_no_data' => 'Die Datei enthält keine Tabelle oder Zeile für diese Datenbank.',
                'query_failed' => 'Die Abfrage ist fehlgeschlagen.',
                'connect_failed' => 'Verbindung zur Datenbank fehlgeschlagen.',
                'import_failed' => 'Anweisung {index} / {total} ist fehlgeschlagen.',
                'import_partial' => 'Davor wurden {done} Anweisungen geschrieben.',
            ],
            'fr' => [
                'invalid_name' => 'Nom invalide.',
                'db_locked' => 'Cette base ne peut pas être ouverte.',
                'forbidden' => 'Accès à la base de ce serveur refusé.',
                'host_missing' => 'Hôte introuvable.',
                'db_missing' => 'Base introuvable.',
                'table_missing' => 'Table introuvable.',
                'varchar_length' => 'La longueur VARCHAR doit être comprise entre 1 et 16383.',
                'decimal_invalid' => 'La précision ou l\'échelle DECIMAL est invalide.',
                'type_unsupported' => 'Type de colonne non pris en charge.',
                'unknown_column' => 'Colonne inconnue.',
                'no_values' => 'Rien à enregistrer.',
                'no_primary' => 'Cette table n\'a pas de clé primaire. Ajoutez une colonne de clé primaire pour modifier les lignes.',
                'primary_missing' => 'Clé primaire manquante.',
                'query_forbidden' => 'Le fichier contient une requête qui ne peut pas être exécutée.',
                'system_schema' => 'Accès à une base système : !{name}.',
                'query_type' => 'Ce type de requête n\'est pas autorisé.',
                'query_denied' => 'Cette requête n\'est pas autorisée.',
                'bad_row' => 'Valeurs de ligne invalides.',
                'row_missing' => 'Ligne introuvable.',
                'column_missing' => 'Colonne introuvable.',
                'last_column' => 'La dernière colonne de la table ne peut pas être supprimée.',
                'bad_operator' => 'Opérateur invalide.',
                'sql_count' => 'Envoyez entre 1 et 20 requêtes.',
                'sql_empty' => 'Le fichier SQL est vide.',
                'sql_size' => 'Le SQL dépasse la limite de 8 Mo.',
                'sql_none' => 'Aucune requête à exécuter.',
                'sql_too_many' => 'Le fichier contient plus de 10000 requêtes. Divisez-le en parties plus petites.',
                'sql_no_data' => 'Le fichier n\'a aucune table ou ligne à écrire dans cette base.',
                'query_failed' => 'La requête a échoué.',
                'connect_failed' => 'Connexion à la base impossible.',
                'import_failed' => 'La requête {index} / {total} a échoué.',
                'import_partial' => '{done} requêtes ont été écrites avant l\'arrêt.',
            ],
            'ru' => [
                'invalid_name' => 'Недопустимое имя.',
                'db_locked' => 'Эту базу нельзя открыть.',
                'forbidden' => 'Нет доступа к базе этого сервера.',
                'host_missing' => 'Хост не найден.',
                'db_missing' => 'База не найдена.',
                'table_missing' => 'Таблица не найдена.',
                'varchar_length' => 'Длина VARCHAR должна быть от 1 до 16383.',
                'decimal_invalid' => 'Точность или масштаб DECIMAL недопустимы.',
                'type_unsupported' => 'Неподдерживаемый тип столбца.',
                'unknown_column' => 'Неизвестный столбец.',
                'no_values' => 'Нечего сохранять.',
                'no_primary' => 'У таблицы нет первичного ключа. Добавьте столбец первичного ключа, чтобы править строки.',
                'primary_missing' => 'Нет первичного ключа.',
                'query_forbidden' => 'В файле есть запрос, который нельзя выполнить.',
                'system_schema' => 'Обращение к системной базе: !{name}.',
                'query_type' => 'Этот тип запроса запрещён.',
                'query_denied' => 'Этот запрос запрещён.',
                'bad_row' => 'Недопустимые значения строки.',
                'row_missing' => 'Строка не найдена.',
                'column_missing' => 'Столбец не найден.',
                'last_column' => 'Последний столбец таблицы нельзя удалить.',
                'bad_operator' => 'Недопустимый оператор.',
                'sql_count' => 'Отправьте от 1 до 20 запросов.',
                'sql_empty' => 'SQL-файл пуст.',
                'sql_size' => 'SQL превышает предел 8 МБ.',
                'sql_none' => 'Нет запроса для выполнения.',
                'sql_too_many' => 'В файле больше 10000 запросов. Разделите его на части.',
                'sql_no_data' => 'В файле нет таблицы или строк для записи в эту базу.',
                'query_failed' => 'Запрос не выполнен.',
                'connect_failed' => 'Не удалось подключиться к базе.',
                'import_failed' => 'Запрос {index} / {total} не выполнен.',
                'import_partial' => 'До остановки записано запросов: {done}.',
            ],
        ];

        return $all[$lang] ?? $all['tr'];
    }

    function petodb_t(string $key, array $replace = []): string
    {
        $lang = petodb_lang();
        $text = petodb_dict($lang)[$key] ?? petodb_dict('en')[$key] ?? petodb_dict('tr')[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    function petodb_maybe(string $message): string
    {
        return preg_match('/^[a-z0-9_]+$/', $message) === 1 ? petodb_t($message) : $message;
    }

    function petodb_client_server(string $serverShort): \Pterodactyl\Models\Server
    {
        $user = auth()->user();
        if (!$user) {
            throw new RuntimeException('forbidden');
        }
        $server = \Pterodactyl\Models\Server::query()->where('uuidShort', $serverShort)->first();
        if (!$server) {
            throw new RuntimeException('db_missing');
        }
        $allowed = (bool) $user->root_admin || (int) $server->owner_id === (int) $user->id;
        if (!$allowed) {
            $subuser = $server->subusers()->where('user_id', $user->id)->first();
            $permissions = $subuser ? (array) $subuser->getAttribute('permissions') : [];
            foreach ($permissions as $permission) {
                if (is_string($permission) && str_starts_with($permission, 'database.')) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (!$allowed) {
            throw new RuntimeException('forbidden');
        }

        return $server;
    }

    function petodb_client_open(string $serverShort, string $schema): array
    {
        petodb_ident($schema);
        $server = petodb_client_server($serverShort);
        $database = Database::query()->with('host')->where('server_id', $server->id)->where('database', $schema)->first();
        if (!$database || !$database->host) {
            throw new RuntimeException('db_missing');
        }
        $host = $database->host;
        config()->set('database.connections.dynamic', [
            'driver' => 'mysql',
            'host' => $host->host,
            'port' => $host->port,
            'database' => $schema,
            'username' => $database->username,
            'password' => decrypt($database->password),
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
        ]);
        DB::purge('dynamic');
        $connection = DB::connection('dynamic');

        return [$connection, $schema, [
            'id' => $schema,
            'name' => $schema,
            'server' => $server->name,
            'host' => $host->name,
            'username' => $database->username,
        ]];
    }

    function petodb_guard(callable $callback)
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            return petodb_fail($exception->errorInfo[2] ?? petodb_t('query_failed'));
        } catch (InvalidArgumentException $exception) {
            return petodb_fail(petodb_maybe($exception->getMessage()));
        } catch (RuntimeException $exception) {
            return petodb_fail(petodb_maybe($exception->getMessage()), $exception->getMessage() === 'forbidden' ? 403 : 404);
        } catch (Throwable $exception) {
            return petodb_fail(petodb_t('connect_failed'));
        }
    }
}

Route::middleware(['auth', AdminAuthenticate::class])->prefix('/hosts/{host}')->whereNumber('host')->group(function () {
    Route::get('/databases', function (int $host) {
        return petodb_guard(function () use ($host) {
            $record = \Pterodactyl\Models\DatabaseHost::query()->find($host);
            if (!$record) {
                throw new RuntimeException('host_missing');
            }

            DB::purge('dynamic');
            app(DynamicDatabaseConnection::class)->set('dynamic', $record, 'mysql');
            $rows = DB::connection('dynamic')->select(
                "SELECT schema_name AS name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys', 'panel') ORDER BY schema_name"
            );
            $linked = Database::query()->with('server')->get()->keyBy('database');

            return response()->json([
                'databases' => array_map(static function ($row) use ($linked, $record) {
                    $name = (string) ((array) $row)['name'];
                    $known = $linked->get($name);

                    return [
                        'id' => $name,
                        'name' => $name,
                        'server' => $known?->server->name ?? '',
                        'host' => $record->name,
                        'username' => $known?->username ?? '',
                    ];
                }, $rows),
            ]);
        });
    });

    Route::get('/databases/{id}/tables', function (int $host, string $id) {
        return petodb_guard(function () use ($host, $id) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            $tables = $connection->select(
                'SELECT table_name AS name, table_rows AS row_estimate, engine AS engine, table_collation AS collation, data_length AS data_length, index_length AS index_length, data_free AS data_free
                 FROM information_schema.tables
                 WHERE table_schema = ? AND table_type = ?
                 ORDER BY table_name',
                [$schemaName, 'BASE TABLE']
            );

            return response()->json([
                'database' => $summary,
                'tables' => array_map(static function ($table) {
                    $table = (array) $table;
                    $data = (int) $table['data_length'];
                    $index = (int) $table['index_length'];

                    return [
                        'name' => (string) $table['name'],
                        'rows' => (int) $table['row_estimate'],
                        'engine' => (string) $table['engine'],
                        'collation' => (string) $table['collation'],
                        'data_length' => $data,
                        'index_length' => $index,
                        'size' => $data + $index,
                        'overhead' => (int) $table['data_free'],
                    ];
                }, $tables),
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables', function (Request $request, int $host, string $id) {
        return petodb_guard(function () use ($request, $host, $id) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            $name = (string) $request->input('name', '');
            petodb_ident($name);
            $connection->statement(
                'CREATE TABLE ' . petodb_ident($name) . ' (`id` INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            return response()->json(['ok' => true, 'name' => $name]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::delete('/databases/{id}/tables/{table}', function (int $host, string $id, string $table) {
        return petodb_guard(function () use ($host, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $connection->statement('DROP TABLE ' . petodb_ident($table));

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::get('/databases/{id}/tables/{table}', function (Request $request, int $host, string $id, string $table) {
        return petodb_guard(function () use ($request, $host, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $page = max(1, (int) $request->query('page', 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            $count = (array) $connection->selectOne('SELECT COUNT(*) AS total FROM ' . petodb_ident($table));
            $rows = $connection->select(
                'SELECT * FROM ' . petodb_ident($table) . ' LIMIT ' . $perPage . ' OFFSET ' . $offset
            );

            return response()->json([
                'database' => $summary,
                'table' => $table,
                'columns' => $columns,
                'primary' => petodb_primary($columns),
                'rows' => array_map(static function ($row) {
                    $out = [];
                    foreach ((array) $row as $key => $value) {
                        $out[$key] = petodb_cell($value);
                    }

                    return $out;
                }, $rows),
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) ($count['total'] ?? 0),
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables/{table}/rows', function (Request $request, int $host, string $id, string $table) {
        return petodb_guard(function () use ($request, $host, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $values = $request->input('values', []);
            if (!is_array($values)) {
                throw new InvalidArgumentException('bad_row');
            }
            $known = [];
            foreach ($columns as $column) {
                $known[$column['name']] = $column;
            }
            $names = [];
            $bindings = [];
            foreach ($values as $name => $value) {
                $name = (string) $name;
                if (!isset($known[$name])) {
                    throw new InvalidArgumentException('unknown_column');
                }
                if (is_array($value)) {
                    continue;
                }
                if (str_contains((string) $known[$name]['extra'], 'auto_increment') && ($value === null || $value === '')) {
                    continue;
                }
                $names[] = petodb_ident($name);
                $bindings[] = $value;
            }
            if ($names === []) {
                $connection->statement('INSERT INTO ' . petodb_ident($table) . ' () VALUES ()');
            } else {
                $connection->insert(
                    'INSERT INTO ' . petodb_ident($table) . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
                    $bindings
                );
            }

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::put('/databases/{id}/tables/{table}/rows', function (Request $request, int $host, string $id, string $table) {
        return petodb_guard(function () use ($request, $host, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $values = $request->input('values', []);
            $keys = $request->input('keys', []);
            if (!is_array($values) || !is_array($keys)) {
                throw new InvalidArgumentException('bad_row');
            }
            [$assignments, $valueBindings] = petodb_assignments($values, $columns, false);
            [$where, $keyBindings] = petodb_where($keys, petodb_primary($columns));
            $affected = $connection->update(
                'UPDATE ' . petodb_ident($table) . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where . ' LIMIT 1',
                array_merge($valueBindings, $keyBindings)
            );
            if ($affected < 1) {
                throw new InvalidArgumentException('row_missing');
            }

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::delete('/databases/{id}/tables/{table}/rows', function (Request $request, int $host, string $id, string $table) {
        return petodb_guard(function () use ($request, $host, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $keys = $request->input('keys', []);
            if (!is_array($keys)) {
                throw new InvalidArgumentException('primary_missing');
            }
            $columns = petodb_columns($connection, $schemaName, $table);
            [$where, $bindings] = petodb_where($keys, petodb_primary($columns));
            $affected = $connection->delete(
                'DELETE FROM ' . petodb_ident($table) . ' WHERE ' . $where . ' LIMIT 1',
                $bindings
            );
            if ($affected < 1) {
                throw new InvalidArgumentException('row_missing');
            }

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables/{table}/columns', function (Request $request, int $host, string $id, string $table) {
        return petodb_guard(function () use ($request, $host, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $name = (string) $request->input('name', '');
            $type = petodb_type_sql((string) $request->input('type', ''), $request->input('length'), $request->input('scale'));
            $nullable = $request->boolean('nullable', true);
            $connection->statement(
                'ALTER TABLE ' . petodb_ident($table) . ' ADD COLUMN ' . petodb_ident($name) . ' ' . $type . ' ' . ($nullable ? 'NULL' : 'NOT NULL')
            );

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::patch('/databases/{id}/tables/{table}/columns/{column}', function (Request $request, int $host, string $id, string $table, string $column) {
        return petodb_guard(function () use ($request, $host, $id, $table, $column) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $known = petodb_columns($connection, $schemaName, $table);
            if (!collect($known)->firstWhere('name', $column)) {
                throw new InvalidArgumentException('column_missing');
            }
            $name = (string) $request->input('name', $column);
            $type = petodb_type_sql((string) $request->input('type', ''), $request->input('length'), $request->input('scale'));
            $nullable = $request->boolean('nullable', true);
            $connection->statement(
                'ALTER TABLE ' . petodb_ident($table) . ' CHANGE ' . petodb_ident($column) . ' ' . petodb_ident($name) . ' ' . $type . ' ' . ($nullable ? 'NULL' : 'NOT NULL')
            );

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+')->where('column', '[A-Za-z0-9_]+');

    Route::delete('/databases/{id}/tables/{table}/columns/{column}', function (int $host, string $id, string $table, string $column) {
        return petodb_guard(function () use ($host, $id, $table, $column) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $known = petodb_columns($connection, $schemaName, $table);
            if (count($known) < 2) {
                throw new InvalidArgumentException('last_column');
            }
            if (!collect($known)->firstWhere('name', $column)) {
                throw new InvalidArgumentException('column_missing');
            }
            $connection->statement('ALTER TABLE ' . petodb_ident($table) . ' DROP COLUMN ' . petodb_ident($column));

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+')->where('column', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables/{table}/truncate', function (int $host, string $id, string $table) {
        return petodb_guard(function () use ($host, $id, $table) {
            [$connection, $schemaName] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $connection->statement('TRUNCATE TABLE ' . petodb_ident($table));

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::get('/databases/{id}/tables/{table}/search', function (Request $request, int $host, string $id, string $table) {
        return petodb_guard(function () use ($request, $host, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_open($host, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $known = array_column($columns, 'name');
            $column = (string) $request->query('column', '');
            $op = (string) $request->query('op', 'like');
            $value = (string) $request->query('q', '');
            $ops = ['like' => 'LIKE', 'eq' => '=', 'ne' => '<>', 'gt' => '>', 'lt' => '<'];
            if (!isset($ops[$op])) {
                throw new InvalidArgumentException('bad_operator');
            }
            $bindings = [];
            if ($column !== '') {
                if (!in_array($column, $known, true)) {
                    throw new InvalidArgumentException('unknown_column');
                }
                $where = petodb_ident($column) . ' ' . $ops[$op] . ' ?';
                $bindings[] = $op === 'like' ? '%' . $value . '%' : $value;
            } else {
                $parts = [];
                foreach ($known as $name) {
                    $parts[] = 'CAST(' . petodb_ident($name) . ' AS CHAR) LIKE ?';
                    $bindings[] = '%' . $value . '%';
                }
                $where = implode(' OR ', $parts);
            }
            $page = max(1, (int) $request->query('page', 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            $count = (array) $connection->selectOne('SELECT COUNT(*) AS total FROM ' . petodb_ident($table) . ' WHERE ' . $where, $bindings);
            $rows = $connection->select(
                'SELECT * FROM ' . petodb_ident($table) . ' WHERE ' . $where . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
                $bindings
            );

            return response()->json([
                'database' => $summary,
                'table' => $table,
                'columns' => $columns,
                'primary' => petodb_primary($columns),
                'rows' => array_map(static function ($row) {
                    $out = [];
                    foreach ((array) $row as $key => $cell) {
                        $out[$key] = petodb_cell($cell);
                    }

                    return $out;
                }, $rows),
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) ($count['total'] ?? 0),
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/sql', function (Request $request, int $host, string $id) {
        return petodb_guard(function () use ($request, $host, $id) {
            [$connection] = petodb_open($host, $id);
            $statements = petodb_split_sql((string) $request->input('sql', ''));
            if ($statements === [] || count($statements) > 20) {
                throw new InvalidArgumentException('sql_count');
            }
            $results = [];
            foreach ($statements as $statement) {
                petodb_assert_sql($statement);
                $first = strtoupper((string) strtok(ltrim($statement), " \t\r\n("));
                if (in_array($first, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'CHECK'], true)) {
                    $rows = array_slice($connection->select($statement), 0, 200);
                    $mapped = array_map(static function ($row) {
                        $out = [];
                        foreach ((array) $row as $key => $cell) {
                            $out[$key] = petodb_cell($cell);
                        }

                        return $out;
                    }, $rows);
                    $results[] = [
                        'statement' => $statement,
                        'columns' => $mapped === [] ? [] : array_keys($mapped[0]),
                        'rows' => $mapped,
                    ];
                    continue;
                }
                $affected = $connection->affectingStatement($statement);
                $results[] = [
                    'statement' => $statement,
                    'affected' => $affected,
                ];
            }

            return response()->json(['results' => $results]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::get('/databases/{id}/export', function (Request $request, int $host, string $id) {
        return petodb_guard(function () use ($request, $host, $id) {
            [$connection, $schemaName] = petodb_open($host, $id);
            $wanted = $request->query('tables');
            $names = [];
            if (is_string($wanted) && $wanted !== '') {
                foreach (explode(',', $wanted) as $name) {
                    petodb_ident($name);
                    $names[] = $name;
                }
            }
            $tables = $connection->select(
                'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name',
                [$schemaName, 'BASE TABLE']
            );
            $sql = '-- Peto DB export ' . $schemaName . "\n\n";
            foreach ($tables as $table) {
                $name = (string) ((array) $table)['name'];
                if ($names !== [] && !in_array($name, $names, true)) {
                    continue;
                }
                petodb_assert_table($connection, $schemaName, $name);
                $create = array_change_key_case((array) $connection->selectOne('SHOW CREATE TABLE ' . petodb_ident($name)), CASE_LOWER);
                $createSql = (string) ($create['create table'] ?? '');
                $sql .= 'DROP TABLE IF EXISTS ' . petodb_ident($name) . ";\n" . $createSql . ";\n\n";
                $rows = $connection->select('SELECT * FROM ' . petodb_ident($name) . ' LIMIT 2000');
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $cols = implode(', ', array_map(static fn ($key) => petodb_ident((string) $key), array_keys($row)));
                    $vals = implode(', ', array_map('petodb_sql_value', array_values($row)));
                    $sql .= 'INSERT INTO ' . petodb_ident($name) . ' (' . $cols . ') VALUES (' . $vals . ");\n";
                }
                $sql .= "\n";
            }

            return response($sql, 200, [
                'Content-Type' => 'application/sql; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $schemaName . '.sql"',
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/import', function (Request $request, int $host, string $id) {
        return petodb_guard(function () use ($request, $host, $id) {
            [$connection, $schemaName] = petodb_open($host, $id);
            $sql = (string) $request->input('sql', '');
            if (trim($sql) === '') {
                throw new InvalidArgumentException('sql_empty');
            }
            if (strlen($sql) > 8000000) {
                throw new InvalidArgumentException('sql_size');
            }
            $statements = petodb_split_sql($sql);
            if ($statements === []) {
                throw new InvalidArgumentException('sql_none');
            }
            if (count($statements) > 10000) {
                throw new InvalidArgumentException('sql_too_many');
            }
            $done = 0;
            $skipped = 0;
            $total = count($statements);
            foreach ($statements as $offset => $statement) {
                $index = $offset + 1;
                try {
                    $run = petodb_prepare_import($statement);
                } catch (InvalidArgumentException $exception) {
                    return petodb_import_stop($index, $total, $done, $exception->getMessage(), $statement);
                }
                if ($run === null) {
                    $skipped++;
                    continue;
                }
                try {
                    $connection->unprepared($run);
                } catch (QueryException $exception) {
                    $code = (string) ($exception->errorInfo[1] ?? '');
                    $detail = (string) ($exception->errorInfo[2] ?? petodb_t('query_failed'));
                    $reason = ($code !== '' ? '#' . $code . ' — ' : '') . $detail;

                    return petodb_import_stop($index, $total, $done, $reason, $run);
                }
                $done++;
            }
            if ($done === 0) {
                throw new InvalidArgumentException('sql_no_data');
            }

            return response()->json([
                'ok' => true,
                'database' => $schemaName,
                'statements' => $done,
                'skipped' => $skipped,
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+');
});

Route::middleware(['auth'])->prefix('/client/{server}')->where(['server' => '[A-Za-z0-9]+'])->group(function () {
    Route::get('/databases', function (string $server) {
        return petodb_guard(function () use ($server) {
            $record = petodb_client_server($server);
            $rows = $record->databases()->orderBy('database')->get();

            return response()->json([
                'databases' => $rows->map(static function ($database) use ($record) {
                    return [
                        'id' => $database->database,
                        'name' => $database->database,
                        'server' => $record->name,
                        'host' => '',
                        'username' => $database->username,
                    ];
                })->all(),
            ]);
        });
    });

    Route::get('/databases/{id}/tables', function (string $server, string $id) {
        return petodb_guard(function () use ($server, $id) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            $tables = $connection->select(
                'SELECT table_name AS name, table_rows AS row_estimate, engine AS engine, table_collation AS collation, data_length AS data_length, index_length AS index_length, data_free AS data_free
                 FROM information_schema.tables
                 WHERE table_schema = ? AND table_type = ?
                 ORDER BY table_name',
                [$schemaName, 'BASE TABLE']
            );

            return response()->json([
                'database' => $summary,
                'tables' => array_map(static function ($table) {
                    $table = (array) $table;
                    $data = (int) $table['data_length'];
                    $index = (int) $table['index_length'];

                    return [
                        'name' => (string) $table['name'],
                        'rows' => (int) $table['row_estimate'],
                        'engine' => (string) $table['engine'],
                        'collation' => (string) $table['collation'],
                        'data_length' => $data,
                        'index_length' => $index,
                        'size' => $data + $index,
                        'overhead' => (int) $table['data_free'],
                    ];
                }, $tables),
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables', function (Request $request, string $server, string $id) {
        return petodb_guard(function () use ($request, $server, $id) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            $name = (string) $request->input('name', '');
            petodb_ident($name);
            $connection->statement(
                'CREATE TABLE ' . petodb_ident($name) . ' (`id` INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            return response()->json(['ok' => true, 'name' => $name]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::delete('/databases/{id}/tables/{table}', function (string $server, string $id, string $table) {
        return petodb_guard(function () use ($server, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $connection->statement('DROP TABLE ' . petodb_ident($table));

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::get('/databases/{id}/tables/{table}', function (Request $request, string $server, string $id, string $table) {
        return petodb_guard(function () use ($request, $server, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $page = max(1, (int) $request->query('page', 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            $count = (array) $connection->selectOne('SELECT COUNT(*) AS total FROM ' . petodb_ident($table));
            $rows = $connection->select(
                'SELECT * FROM ' . petodb_ident($table) . ' LIMIT ' . $perPage . ' OFFSET ' . $offset
            );

            return response()->json([
                'database' => $summary,
                'table' => $table,
                'columns' => $columns,
                'primary' => petodb_primary($columns),
                'rows' => array_map(static function ($row) {
                    $out = [];
                    foreach ((array) $row as $key => $value) {
                        $out[$key] = petodb_cell($value);
                    }

                    return $out;
                }, $rows),
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) ($count['total'] ?? 0),
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables/{table}/rows', function (Request $request, string $server, string $id, string $table) {
        return petodb_guard(function () use ($request, $server, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $values = $request->input('values', []);
            if (!is_array($values)) {
                throw new InvalidArgumentException('bad_row');
            }
            $known = [];
            foreach ($columns as $column) {
                $known[$column['name']] = $column;
            }
            $names = [];
            $bindings = [];
            foreach ($values as $name => $value) {
                $name = (string) $name;
                if (!isset($known[$name])) {
                    throw new InvalidArgumentException('unknown_column');
                }
                if (is_array($value)) {
                    continue;
                }
                if (str_contains((string) $known[$name]['extra'], 'auto_increment') && ($value === null || $value === '')) {
                    continue;
                }
                $names[] = petodb_ident($name);
                $bindings[] = $value;
            }
            if ($names === []) {
                $connection->statement('INSERT INTO ' . petodb_ident($table) . ' () VALUES ()');
            } else {
                $connection->insert(
                    'INSERT INTO ' . petodb_ident($table) . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
                    $bindings
                );
            }

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::put('/databases/{id}/tables/{table}/rows', function (Request $request, string $server, string $id, string $table) {
        return petodb_guard(function () use ($request, $server, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $values = $request->input('values', []);
            $keys = $request->input('keys', []);
            if (!is_array($values) || !is_array($keys)) {
                throw new InvalidArgumentException('bad_row');
            }
            [$assignments, $valueBindings] = petodb_assignments($values, $columns, false);
            [$where, $keyBindings] = petodb_where($keys, petodb_primary($columns));
            $affected = $connection->update(
                'UPDATE ' . petodb_ident($table) . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where . ' LIMIT 1',
                array_merge($valueBindings, $keyBindings)
            );
            if ($affected < 1) {
                throw new InvalidArgumentException('row_missing');
            }

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::delete('/databases/{id}/tables/{table}/rows', function (Request $request, string $server, string $id, string $table) {
        return petodb_guard(function () use ($request, $server, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $keys = $request->input('keys', []);
            if (!is_array($keys)) {
                throw new InvalidArgumentException('primary_missing');
            }
            $columns = petodb_columns($connection, $schemaName, $table);
            [$where, $bindings] = petodb_where($keys, petodb_primary($columns));
            $affected = $connection->delete(
                'DELETE FROM ' . petodb_ident($table) . ' WHERE ' . $where . ' LIMIT 1',
                $bindings
            );
            if ($affected < 1) {
                throw new InvalidArgumentException('row_missing');
            }

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables/{table}/columns', function (Request $request, string $server, string $id, string $table) {
        return petodb_guard(function () use ($request, $server, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $name = (string) $request->input('name', '');
            $type = petodb_type_sql((string) $request->input('type', ''), $request->input('length'), $request->input('scale'));
            $nullable = $request->boolean('nullable', true);
            $connection->statement(
                'ALTER TABLE ' . petodb_ident($table) . ' ADD COLUMN ' . petodb_ident($name) . ' ' . $type . ' ' . ($nullable ? 'NULL' : 'NOT NULL')
            );

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::patch('/databases/{id}/tables/{table}/columns/{column}', function (Request $request, string $server, string $id, string $table, string $column) {
        return petodb_guard(function () use ($request, $server, $id, $table, $column) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $known = petodb_columns($connection, $schemaName, $table);
            if (!collect($known)->firstWhere('name', $column)) {
                throw new InvalidArgumentException('column_missing');
            }
            $name = (string) $request->input('name', $column);
            $type = petodb_type_sql((string) $request->input('type', ''), $request->input('length'), $request->input('scale'));
            $nullable = $request->boolean('nullable', true);
            $connection->statement(
                'ALTER TABLE ' . petodb_ident($table) . ' CHANGE ' . petodb_ident($column) . ' ' . petodb_ident($name) . ' ' . $type . ' ' . ($nullable ? 'NULL' : 'NOT NULL')
            );

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+')->where('column', '[A-Za-z0-9_]+');

    Route::delete('/databases/{id}/tables/{table}/columns/{column}', function (string $server, string $id, string $table, string $column) {
        return petodb_guard(function () use ($server, $id, $table, $column) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $known = petodb_columns($connection, $schemaName, $table);
            if (count($known) < 2) {
                throw new InvalidArgumentException('last_column');
            }
            if (!collect($known)->firstWhere('name', $column)) {
                throw new InvalidArgumentException('column_missing');
            }
            $connection->statement('ALTER TABLE ' . petodb_ident($table) . ' DROP COLUMN ' . petodb_ident($column));

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+')->where('column', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/tables/{table}/truncate', function (string $server, string $id, string $table) {
        return petodb_guard(function () use ($server, $id, $table) {
            [$connection, $schemaName] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $connection->statement('TRUNCATE TABLE ' . petodb_ident($table));

            return response()->json(['ok' => true]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::get('/databases/{id}/tables/{table}/search', function (Request $request, string $server, string $id, string $table) {
        return petodb_guard(function () use ($request, $server, $id, $table) {
            [$connection, $schemaName, $summary] = petodb_client_open($server, $id);
            petodb_assert_table($connection, $schemaName, $table);
            $columns = petodb_columns($connection, $schemaName, $table);
            $known = array_column($columns, 'name');
            $column = (string) $request->query('column', '');
            $op = (string) $request->query('op', 'like');
            $value = (string) $request->query('q', '');
            $ops = ['like' => 'LIKE', 'eq' => '=', 'ne' => '<>', 'gt' => '>', 'lt' => '<'];
            if (!isset($ops[$op])) {
                throw new InvalidArgumentException('bad_operator');
            }
            $bindings = [];
            if ($column !== '') {
                if (!in_array($column, $known, true)) {
                    throw new InvalidArgumentException('unknown_column');
                }
                $where = petodb_ident($column) . ' ' . $ops[$op] . ' ?';
                $bindings[] = $op === 'like' ? '%' . $value . '%' : $value;
            } else {
                $parts = [];
                foreach ($known as $name) {
                    $parts[] = 'CAST(' . petodb_ident($name) . ' AS CHAR) LIKE ?';
                    $bindings[] = '%' . $value . '%';
                }
                $where = implode(' OR ', $parts);
            }
            $page = max(1, (int) $request->query('page', 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            $count = (array) $connection->selectOne('SELECT COUNT(*) AS total FROM ' . petodb_ident($table) . ' WHERE ' . $where, $bindings);
            $rows = $connection->select(
                'SELECT * FROM ' . petodb_ident($table) . ' WHERE ' . $where . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
                $bindings
            );

            return response()->json([
                'database' => $summary,
                'table' => $table,
                'columns' => $columns,
                'primary' => petodb_primary($columns),
                'rows' => array_map(static function ($row) {
                    $out = [];
                    foreach ((array) $row as $key => $cell) {
                        $out[$key] = petodb_cell($cell);
                    }

                    return $out;
                }, $rows),
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) ($count['total'] ?? 0),
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+')->where('table', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/sql', function (Request $request, string $server, string $id) {
        return petodb_guard(function () use ($request, $server, $id) {
            [$connection] = petodb_client_open($server, $id);
            $statements = petodb_split_sql((string) $request->input('sql', ''));
            if ($statements === [] || count($statements) > 20) {
                throw new InvalidArgumentException('sql_count');
            }
            $results = [];
            foreach ($statements as $statement) {
                petodb_assert_sql($statement);
                $first = strtoupper((string) strtok(ltrim($statement), " \t\r\n("));
                if (in_array($first, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'CHECK'], true)) {
                    $rows = array_slice($connection->select($statement), 0, 200);
                    $mapped = array_map(static function ($row) {
                        $out = [];
                        foreach ((array) $row as $key => $cell) {
                            $out[$key] = petodb_cell($cell);
                        }

                        return $out;
                    }, $rows);
                    $results[] = [
                        'statement' => $statement,
                        'columns' => $mapped === [] ? [] : array_keys($mapped[0]),
                        'rows' => $mapped,
                    ];
                    continue;
                }
                $affected = $connection->affectingStatement($statement);
                $results[] = [
                    'statement' => $statement,
                    'affected' => $affected,
                ];
            }

            return response()->json(['results' => $results]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::get('/databases/{id}/export', function (Request $request, string $server, string $id) {
        return petodb_guard(function () use ($request, $server, $id) {
            [$connection, $schemaName] = petodb_client_open($server, $id);
            $wanted = $request->query('tables');
            $names = [];
            if (is_string($wanted) && $wanted !== '') {
                foreach (explode(',', $wanted) as $name) {
                    petodb_ident($name);
                    $names[] = $name;
                }
            }
            $tables = $connection->select(
                'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name',
                [$schemaName, 'BASE TABLE']
            );
            $sql = '-- Peto DB export ' . $schemaName . "\n\n";
            foreach ($tables as $table) {
                $name = (string) ((array) $table)['name'];
                if ($names !== [] && !in_array($name, $names, true)) {
                    continue;
                }
                petodb_assert_table($connection, $schemaName, $name);
                $create = array_change_key_case((array) $connection->selectOne('SHOW CREATE TABLE ' . petodb_ident($name)), CASE_LOWER);
                $createSql = (string) ($create['create table'] ?? '');
                $sql .= 'DROP TABLE IF EXISTS ' . petodb_ident($name) . ";\n" . $createSql . ";\n\n";
                $rows = $connection->select('SELECT * FROM ' . petodb_ident($name) . ' LIMIT 2000');
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $cols = implode(', ', array_map(static fn ($key) => petodb_ident((string) $key), array_keys($row)));
                    $vals = implode(', ', array_map('petodb_sql_value', array_values($row)));
                    $sql .= 'INSERT INTO ' . petodb_ident($name) . ' (' . $cols . ') VALUES (' . $vals . ");\n";
                }
                $sql .= "\n";
            }

            return response($sql, 200, [
                'Content-Type' => 'application/sql; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $schemaName . '.sql"',
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+');

    Route::post('/databases/{id}/import', function (Request $request, string $server, string $id) {
        return petodb_guard(function () use ($request, $server, $id) {
            [$connection, $schemaName] = petodb_client_open($server, $id);
            $sql = (string) $request->input('sql', '');
            if (trim($sql) === '') {
                throw new InvalidArgumentException('sql_empty');
            }
            if (strlen($sql) > 8000000) {
                throw new InvalidArgumentException('sql_size');
            }
            $statements = petodb_split_sql($sql);
            if ($statements === []) {
                throw new InvalidArgumentException('sql_none');
            }
            if (count($statements) > 10000) {
                throw new InvalidArgumentException('sql_too_many');
            }
            $done = 0;
            $skipped = 0;
            $total = count($statements);
            foreach ($statements as $offset => $statement) {
                $index = $offset + 1;
                try {
                    $run = petodb_prepare_import($statement);
                } catch (InvalidArgumentException $exception) {
                    return petodb_import_stop($index, $total, $done, $exception->getMessage(), $statement);
                }
                if ($run === null) {
                    $skipped++;
                    continue;
                }
                try {
                    $connection->unprepared($run);
                } catch (QueryException $exception) {
                    $code = (string) ($exception->errorInfo[1] ?? '');
                    $detail = (string) ($exception->errorInfo[2] ?? petodb_t('query_failed'));
                    $reason = ($code !== '' ? '#' . $code . ' — ' : '') . $detail;

                    return petodb_import_stop($index, $total, $done, $reason, $run);
                }
                $done++;
            }
            if ($done === 0) {
                throw new InvalidArgumentException('sql_no_data');
            }

            return response()->json([
                'ok' => true,
                'database' => $schemaName,
                'statements' => $done,
                'skipped' => $skipped,
            ]);
        });
    })->where('id', '[A-Za-z0-9_]+');
});
