<?php

namespace wise\backup\helper;

/**
 * SQL 语句分割工具类
 */
class SqlSplitter
{
    /**
     * 将多语句 SQL 按分号分割为数组
     *
     * 正确处理字符串内的分号、DELIMITER 指令、存储过程/函数内的分号
     *
     * @param string $sql 原始 SQL 文本
     * @return array 分割后的 SQL 语句数组
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $delimiter = ';';
        $inCustomDelimiter = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (strtoupper(substr($sql, $i, 10)) === 'DELIMITER ') {
                $endPos = strpos($sql, "\n", $i);
                if ($endPos === false) {
                    $endPos = $length;
                }
                $delimiterLine = substr($sql, $i, $endPos - $i);
                $parts = preg_split('/\s+/', $delimiterLine);
                if (count($parts) >= 2) {
                    $newDelimiter = trim($parts[1]);
                    if ($newDelimiter !== ';') {
                        $delimiter = $newDelimiter;
                        $inCustomDelimiter = true;
                    } else {
                        $delimiter = ';';
                        $inCustomDelimiter = false;
                    }
                }
                $i = $endPos;
                continue;
            }

            $delimLen = strlen($delimiter);
            if (substr($sql, $i, $delimLen) === $delimiter) {
                $current = trim($current);
                if ($current !== '') {
                    $statements[] = $current;
                    $current = '';
                }
                $i += $delimLen - 1;
                continue;
            }

            if ($char === "'") {
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $current .= $sql[$i];
                    if ($sql[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $current .= $sql[$i];
                        }
                    } elseif ($sql[$i] === "'") {
                        if ($i + 1 < $length && $sql[$i + 1] === "'") {
                            $i++;
                            $current .= $sql[$i];
                        } else {
                            break;
                        }
                    }
                    $i++;
                }
                continue;
            }

            if ($char === '"') {
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $current .= $sql[$i];
                    if ($sql[$i] === '\\') {
                        $i++;
                        if ($i < $length) {
                            $current .= $sql[$i];
                        }
                    } elseif ($sql[$i] === '"') {
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === '`') {
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $current .= $sql[$i];
                    if ($sql[$i] === '`') {
                        break;
                    }
                    $i++;
                }
                continue;
            }

            $current .= $char;
        }

        $current = trim($current);
        if ($current !== '' && (!$inCustomDelimiter || $delimiter === ';')) {
            $statements[] = $current;
        }

        return $statements;
    }

    /**
     * 按表分组 SQL 内容
     *
     * 识别 CREATE TABLE / INSERT INTO 边界，将 SQL 按表名分组
     *
     * @param string $sqlContent SQL 内容
     * @return array ['table_name' => 'sql_content', ...]
     */
    public static function splitByTable(string $sqlContent): array
    {
        $result = [];
        $lines = explode("\n", $sqlContent);
        $currentTable = '';
        $currentSql = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $trimmed, $matches)) {
                if ($currentTable !== '' && trim($currentSql) !== '') {
                    $result[$currentTable] = trim($currentSql);
                }
                $currentTable = $matches[1];
                $currentSql = $line . "\n";
                continue;
            }

            if (preg_match('/INSERT\s+INTO\s+`?(\w+)`?/i', $trimmed, $matches)) {
                if ($currentTable === '' || $currentTable !== $matches[1]) {
                    if ($currentTable !== '' && trim($currentSql) !== '') {
                        $result[$currentTable] = trim($currentSql);
                    }
                    $currentTable = $matches[1];
                    $currentSql = $line . "\n";
                    continue;
                }
            }

            $currentSql .= $line . "\n";
        }

        if ($currentTable !== '' && trim($currentSql) !== '') {
            $result[$currentTable] = trim($currentSql);
        }

        return $result;
    }
}
