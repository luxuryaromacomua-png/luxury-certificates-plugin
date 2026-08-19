<?php
/**
 * Writes gift-certificate records into the standalone `luxury_certificates`
 * MySQL database (separate from WordPress). Kept dependency-free (PDO only) so
 * the same schema/logic is trivial to mirror in the PHP Telegram bot.
 *
 * Connection settings can be overridden in wp-config.php by defining any of:
 *   LGC_DB_HOST, LGC_DB_PORT, LGC_DB_NAME, LGC_DB_USER, LGC_DB_PASS
 */

if (!defined('ABSPATH')) {
    exit;
}

class LGC_Certificate_Store
{

    /** @var PDO|null */
    private static $pdo = null;

    private static function cfg($const, $default)
    {
        return defined($const) ? constant($const) : $default;
    }

    /**
     * Lazily open (and cache) the PDO connection.
     *
     * @return PDO|null Null if the connection could not be established.
     */
    private static function pdo()
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = self::cfg('LGC_DB_HOST', '127.0.0.1');
        $port = self::cfg('LGC_DB_PORT', '3306');
        $name = self::cfg('LGC_DB_NAME', 'luxury_certificates');
        $user = self::cfg('LGC_DB_USER', 'root');
        $pass = self::cfg('LGC_DB_PASS', 'root');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ));
        } catch (Exception $e) {
            error_log('[LGC] Certificate DB connection failed: ' . $e->getMessage());
            self::$pdo = null;
        }

        return self::$pdo;
    }

    /**
     * Create one certificate. Assigns the next sequential number atomically.
     *
     * @param array $data amount, recipient_name, email, phone, wc_order_id, source
     * @return int|false The assigned certificate number, or false on failure.
     */
    public static function create(array $data)
    {
        $pdo = self::pdo();
        if (!$pdo) {
            return false;
        }

        try {
            $pdo->beginTransaction();

            $next = (int)$pdo->query(
                'SELECT COALESCE(MAX(number), 0) + 1 FROM certificates FOR UPDATE'
            )->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT INTO certificates
					(number, amount, status, recipient_name, email, phone, wc_order_id, source, created_at)
				 VALUES
					(:number, :amount, "active", :name, :email, :phone, :order_id, :source, NOW())'
            );
            $stmt->execute(array(
                ':number' => $next,
                ':amount' => (float)$data['amount'],
                ':name' => !empty($data['recipient_name']) ? $data['recipient_name'] : null,
                ':email' => !empty($data['email']) ? $data['email'] : null,
                ':phone' => !empty($data['phone']) ? $data['phone'] : null,
                ':order_id' => !empty($data['wc_order_id']) ? (int)$data['wc_order_id'] : null,
                ':source' => !empty($data['source']) ? $data['source'] : 'wp',
            ));

            $pdo->commit();
            return $next;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[LGC] Certificate insert failed: ' . $e->getMessage());
            return false;
        }
    }
}
