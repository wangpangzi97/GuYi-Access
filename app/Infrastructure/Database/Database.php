<?php
require_once dirname(__DIR__, 3) . '/config/config.php';

if (!class_exists('Database')) {
    
    class Database {
        public $pdo;
        
        public function __construct(bool $migrate = false) {
            try {
                $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ];
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                $this->pdo->exec("SET time_zone='+00:00'");
                if ($migrate) $this->createTables();
                
            } catch (PDOException $e) {
                error_log('DB Connection Error: ' . $e->getMessage());
                throw new RuntimeException('Database unavailable', 0, $e);
            }
        }
        
        private function createTables() {
            $tableOptions = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS applications (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                app_name VARCHAR(100) NOT NULL UNIQUE, 
                app_key VARCHAR(64) NOT NULL UNIQUE, 
                api_secret VARCHAR(64) DEFAULT '',
                key_version INT NOT NULL DEFAULT 1,
                app_version VARCHAR(32) DEFAULT '', 
                status TINYINT DEFAULT 1, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                notes TEXT,
                update_url VARCHAR(255) DEFAULT '',
                force_update TINYINT DEFAULT 0
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS app_variables (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                app_id INT NOT NULL, 
                key_name VARCHAR(50) NOT NULL, 
                value TEXT, 
                is_public TINYINT DEFAULT 0, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                INDEX idx_app_var (app_id, key_name)
            ) $tableOptions");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS cards (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                card_code VARCHAR(50) UNIQUE NOT NULL, 
                card_type VARCHAR(20) NOT NULL, 
                status TINYINT DEFAULT 0, 
                device_hash VARCHAR(100), 
                used_time DATETIME, 
                expire_time DATETIME, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                notes TEXT, 
                app_id INT DEFAULT 0, 
                custom_data TEXT,
                duration INT DEFAULT 0,
                last_unbind_at BIGINT NOT NULL DEFAULT 0,
                INDEX idx_card_app (app_id), 
                INDEX idx_card_hash (device_hash)
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS usage_logs (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                card_code VARCHAR(50) NOT NULL, 
                card_type VARCHAR(20) NOT NULL, 
                device_hash VARCHAR(100) NOT NULL, 
                ip_address VARCHAR(45), 
                user_agent TEXT, 
                access_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                result VARCHAR(100), 
                app_name VARCHAR(100) DEFAULT 'System', 
                INDEX idx_log_time (access_time)
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS active_devices (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                device_hash VARCHAR(100) NOT NULL, 
                card_code VARCHAR(50) UNIQUE NOT NULL, 
                card_type VARCHAR(20) NOT NULL, 
                activate_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                expire_time DATETIME NOT NULL, 
                status TINYINT DEFAULT 1, 
                app_id INT DEFAULT 0, 
                last_heartbeat DATETIME NULL DEFAULT NULL,
                INDEX idx_dev_hash (device_hash), 
                INDEX idx_dev_expire (expire_time)
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin (
                id INT PRIMARY KEY, 
                username VARCHAR(50) UNIQUE NOT NULL, 
                password_hash VARCHAR(255) NOT NULL
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                key_name VARCHAR(50) PRIMARY KEY,
                value TEXT
            ) $tableOptions");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS blacklists (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                type VARCHAR(20) NOT NULL, 
                value VARCHAR(100) NOT NULL UNIQUE, 
                reason TEXT, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bl_type_value (type, value)
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_nonces (principal CHAR(64) NOT NULL, nonce CHAR(32) NOT NULL, expires_at BIGINT NOT NULL, PRIMARY KEY(principal,nonce), INDEX(expires_at)) $tableOptions");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_sessions (session_id CHAR(64) PRIMARY KEY, app_id INT NOT NULL, card_code VARCHAR(50) NOT NULL, device_hash VARCHAR(100) NOT NULL, key_version INT NOT NULL, expires_at BIGINT NOT NULL, INDEX(card_code), INDEX(expires_at)) $tableOptions");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (bucket CHAR(64) PRIMARY KEY, hits INT NOT NULL, expires_at BIGINT NOT NULL, INDEX(expires_at)) $tableOptions");
            foreach (['applications'=>['key_version'=>'INT NOT NULL DEFAULT 1'], 'cards'=>['last_unbind_at'=>'BIGINT NOT NULL DEFAULT 0']] as $table=>$columns) {
                foreach ($columns as $column=>$definition) {
                    $check=$this->pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                    if (!$check->fetch()) $this->pdo->exec("ALTER TABLE `$table` ADD `$column` $definition");
                }
            }
            try {
                // 动态修补新字段，兼容老版本
                $checkSecret = $this->pdo->query("SHOW COLUMNS FROM `applications` LIKE 'api_secret'");
                if ($checkSecret->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `applications` ADD `api_secret` VARCHAR(64) DEFAULT ''");

                }
                
                $checkHeartbeat = $this->pdo->query("SHOW COLUMNS FROM `active_devices` LIKE 'last_heartbeat'");
                if ($checkHeartbeat->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `active_devices` ADD `last_heartbeat` DATETIME NULL DEFAULT NULL");
                }

                $checkCards = $this->pdo->query("SHOW COLUMNS FROM `cards` LIKE 'app_id'");
                if ($checkCards->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `cards` ADD `app_id` INT DEFAULT 0");
                    $this->pdo->exec("ALTER TABLE `cards` ADD INDEX `idx_card_app` (`app_id`)");
                }
                $checkDevices = $this->pdo->query("SHOW COLUMNS FROM `active_devices` LIKE 'app_id'");
                if ($checkDevices->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `active_devices` ADD `app_id` INT DEFAULT 0");
                }
                $checkLogs = $this->pdo->query("SHOW COLUMNS FROM `usage_logs` LIKE 'app_name'");
                if ($checkLogs->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `usage_logs` ADD `app_name` VARCHAR(100) DEFAULT 'System'");
                }
                $checkUpdate = $this->pdo->query("SHOW COLUMNS FROM `applications` LIKE 'update_url'");
                if ($checkUpdate->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `applications` ADD `update_url` VARCHAR(255) DEFAULT ''");
                    $this->pdo->exec("ALTER TABLE `applications` ADD `force_update` TINYINT DEFAULT 0");
                }
                $checkCustom = $this->pdo->query("SHOW COLUMNS FROM `cards` LIKE 'custom_data'");
                if ($checkCustom->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `cards` ADD `custom_data` TEXT");
                    $this->pdo->exec("ALTER TABLE `cards` ADD `duration` INT DEFAULT 0");
                }
                
                // 🔥 移植：追加免控特权支持
                $checkBrowser = $this->pdo->query("SHOW COLUMNS FROM `cards` LIKE 'browser_whitelist'");
                if ($checkBrowser->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `cards` ADD `browser_whitelist` TINYINT DEFAULT 0");
                }
            } catch (PDOException $e) { throw $e; }
        }

        public function getSystemSettings() {
            $stmt = $this->pdo->query("SELECT * FROM system_settings");
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[$row['key_name']] = $row['value'];
            }
            return $data;
        }

        public function saveSystemSettings($settings) {
            $stmt = $this->pdo->prepare("REPLACE INTO system_settings (key_name, value) VALUES (?, ?)");
            foreach ($settings as $key => $val) {
                $stmt->execute([$key, $val]);
            }
        }
        
        public function getAdminUsername() {
            return $this->pdo->query("SELECT username FROM admin WHERE id=1")->fetchColumn() ?: 'GuYi';
        }
        
        public function updateAdminUsername($newUsername) {
            $this->pdo->prepare("UPDATE admin SET username=? WHERE id=1")->execute([$newUsername]);
        }

        public function createApp($name, $version = '', $notes = '') {
            $appKey = bin2hex(random_bytes(32));
            $apiSecret = bin2hex(random_bytes(32)); 
            $stmt = $this->pdo->prepare("INSERT INTO applications (app_name, app_key, api_secret, app_version, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $appKey, $apiSecret, $version, $notes]);
            return $appKey;
        }

        public function resetAppSecret($id) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare("UPDATE applications SET api_secret=?, key_version=key_version+1 WHERE id=?")->execute([bin2hex(random_bytes(32)), $id]);
                $this->pdo->prepare("DELETE FROM api_sessions WHERE app_id=?")->execute([$id]);
                $this->pdo->commit();
            } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        }

        public function updateApp($id, $name, $version, $notes, $updateUrl = '', $forceUpdate = 0) {
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM applications WHERE app_name = ? AND id != ?");
            $check->execute([$name, $id]);
            if ($check->fetchColumn() > 0) throw new Exception("应用名称已存在");

            $stmt = $this->pdo->prepare("UPDATE applications SET app_name = ?, app_version = ?, notes = ?, update_url = ?, force_update = ? WHERE id = ?");
            $stmt->execute([$name, $version, $notes, $updateUrl, $forceUpdate, $id]);
        }

        public function getApps() {
            return $this->pdo->query("SELECT *, (SELECT COUNT(*) FROM cards WHERE cards.app_id = applications.id) as card_count FROM applications ORDER BY create_time DESC")->fetchAll(PDO::FETCH_ASSOC);
        }

        public function toggleAppStatus($id) { $this->pdo->prepare("UPDATE applications SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE id = ?")->execute([$id]); }

        public function deleteApp($id) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cards WHERE app_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) throw new Exception("无法删除：该应用下仍有 {$count} 张卡密。");
            $this->pdo->prepare("DELETE FROM app_variables WHERE app_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$id]);
        }

        public function addAppVariable($appId, $key, $value, $isPublic) {
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM app_variables WHERE app_id = ? AND key_name = ?");
            $check->execute([$appId, $key]);
            if ($check->fetchColumn() > 0) throw new Exception("变量名重复");
            $stmt = $this->pdo->prepare("INSERT INTO app_variables (app_id, key_name, value, is_public) VALUES (?, ?, ?, ?)");
            $stmt->execute([$appId, $key, $value, $isPublic]);
        }

        public function deleteAppVariable($id) { $this->pdo->prepare("DELETE FROM app_variables WHERE id = ?")->execute([$id]); }

        public function getAppVariables($appId, $onlyPublic = false) {
            $sql = "SELECT * FROM app_variables WHERE app_id = ?";
            if ($onlyPublic) $sql .= " AND is_public = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$appId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public function updateAppVariable($id, $key, $value, $isPublic) {
            $stmt = $this->pdo->prepare("SELECT app_id FROM app_variables WHERE id = ?");
            $stmt->execute([$id]);
            $appId = $stmt->fetchColumn();
            if (!$appId) throw new Exception("变量不存在");
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM app_variables WHERE app_id = ? AND key_name = ? AND id != ?");
            $check->execute([$appId, $key, $id]);
            if ($check->fetchColumn() > 0) throw new Exception("变量名重复");
            $this->pdo->prepare("UPDATE app_variables SET key_name=?, value=?, is_public=? WHERE id=?")->execute([$key, $value, $isPublic, $id]);
        }
        
        public function getAppIdByKey($appKey, bool $lock = false) {
            $stmt = $this->pdo->prepare("SELECT id, status, app_name, app_version, notes, update_url, force_update, api_secret, key_version FROM applications WHERE app_key = ?" . ($lock ? " FOR UPDATE" : ""));
            $stmt->execute([$appKey]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        public function verifyCard($cardCode, $deviceHash, $appKey = null, $customData = null) {
            $own = !$this->pdo->inTransaction();
            if ($own) $this->pdo->beginTransaction();
            try {
                $app=$this->getAppIdByKey($appKey ?? '', true);
                if (!$app || (int)$app['status']!==1) $result=['success'=>false,'message'=>'应用不可用'];
                else $result=$this->verifyCardLocked($cardCode,$deviceHash,$app,$customData);
                if ($own) $this->pdo->commit();
                return $result;
            } catch (Throwable $e) { if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        }

        private function verifyCardLocked($cardCode,$deviceHash,array $app,$customData) {
            $ip=$_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $bl=$this->pdo->prepare("SELECT id FROM blacklists WHERE (type='ip' AND value=?) OR (type='device' AND value=?)"); $bl->execute([$ip,$deviceHash]);
            if ($bl->fetch()) return ['success'=>false,'message'=>'访问受限'];
            $stmt=$this->pdo->prepare('SELECT * FROM cards WHERE card_code=? AND app_id=? FOR UPDATE'); $stmt->execute([$cardCode,$app['id']]); $card=$stmt->fetch();
            if (!$card || !in_array((int)$card['status'],[0,1],true)) return ['success'=>false,'message'=>'卡密无效或不可用'];
            if ($deviceHash==='') return ['success'=>false,'message'=>'必须提供设备标识'];
            if ((int)$card['status']===1 && strtotime($card['expire_time'])<=time()) return ['success'=>false,'message'=>'卡密已过期'];
            if (!empty($card['device_hash']) && !hash_equals($card['device_hash'],$deviceHash)) return ['success'=>false,'message'=>'卡密已绑定其他设备'];
            $customData=($customData!==null && $customData!=='')?$customData:($card['custom_data'] ?? '');
            if ((int)$card['status']===0) {
                $duration=(int)$card['duration']>0?(int)$card['duration']:(CARD_TYPES[$card['card_type']]['duration'] ?? 0);
                if ($duration<=0) return ['success'=>false,'message'=>'卡密时长无效'];
                $this->pdo->prepare('UPDATE cards SET status=1,device_hash=?,used_time=NOW(),expire_time=DATE_ADD(NOW(),INTERVAL ? SECOND),custom_data=? WHERE id=?')->execute([$deviceHash,$duration,$customData,$card['id']]);
            } else $this->pdo->prepare('UPDATE cards SET device_hash=?,custom_data=? WHERE id=?')->execute([$deviceHash,$customData,$card['id']]);
            $stmt=$this->pdo->prepare('SELECT expire_time FROM cards WHERE id=?'); $stmt->execute([$card['id']]); $expires=$stmt->fetchColumn();
            $this->pdo->prepare('INSERT INTO active_devices (device_hash,card_code,card_type,expire_time,status,app_id,last_heartbeat) VALUES (?,?,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE device_hash=VALUES(device_hash),expire_time=VALUES(expire_time),status=1,last_heartbeat=NOW()')->execute([$deviceHash,$cardCode,$card['card_type'],$expires,$app['id']]);
            $this->logUsage('[redacted]',$card['card_type'],$deviceHash,$ip,substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,500),'验证成功',$app['app_name']);
            return ['success'=>true,'message'=>'OK','expire_time'=>$expires,'app_id'=>$app['id'],'custom_data'=>$customData];
        }

        public function unbindCardByApi($cardCode,$appId,$deviceHash) {
            $own=!$this->pdo->inTransaction(); if ($own) $this->pdo->beginTransaction();
            try {
                $s=$this->pdo->prepare('SELECT * FROM cards WHERE card_code=? AND app_id=? AND status=1 FOR UPDATE'); $s->execute([$cardCode,$appId]); $c=$s->fetch();
                if (!$c || !hash_equals((string)$c['device_hash'],$deviceHash) || (int)$c['last_unbind_at']>time()-86400 || strtotime($c['expire_time'])<time()+43200) { if ($own) $this->pdo->rollBack(); return false; }
                $this->pdo->prepare('UPDATE cards SET device_hash=NULL,expire_time=DATE_SUB(expire_time,INTERVAL 12 HOUR),last_unbind_at=? WHERE id=?')->execute([time(),$c['id']]);
                $this->pdo->prepare('DELETE FROM active_devices WHERE card_code=?')->execute([$cardCode]);
                $this->pdo->prepare('DELETE FROM api_sessions WHERE card_code=?')->execute([$cardCode]);
                if ($own) $this->pdo->commit(); return true;
            } catch (Throwable $e) { if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        }

        public function batchDeleteCards($ids) { 
            if (empty($ids)) return 0; 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $own=!$this->pdo->inTransaction(); if ($own) $this->pdo->beginTransaction();
            try {
                if (!empty($codes)) {
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));
                    $this->pdo->prepare("DELETE FROM active_devices WHERE card_code IN ($codePlaceholders)")->execute($codes);
                }
                $delStmt = $this->pdo->prepare("DELETE FROM cards WHERE id IN ($placeholders)");
                $delStmt->execute($ids);
                $count = $delStmt->rowCount();
                if ($own) $this->pdo->commit();
                return $count;
            } catch (Throwable $e) {
                if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
        }

        public function cleanupExpiredCards() {
            $stmt = $this->pdo->query("SELECT id, card_code FROM cards WHERE status = 1 AND expire_time < NOW()");
            $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($cards)) return 0;

            $ids = array_column($cards, 'id');
            $codes = array_column($cards, 'card_code');

            $this->pdo->beginTransaction();
            try {
                if (!empty($codes)) {
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));
                    $this->pdo->prepare("DELETE FROM active_devices WHERE card_code IN ($codePlaceholders)")->execute($codes);
                }
                $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $this->pdo->prepare("DELETE FROM cards WHERE id IN ($idPlaceholders)")->execute($ids);
                $count = count($ids);
                $this->pdo->commit();
                return $count;
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        public function batchUnbindCards($ids) { 
            if (empty($ids)) return 0; 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $own=!$this->pdo->inTransaction(); if ($own) $this->pdo->beginTransaction();
            try { 
                $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders) FOR UPDATE");
                $stmt->execute($ids); 
                $codes = $stmt->fetchAll(PDO::FETCH_COLUMN); 
                if($codes) { 
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?')); 
                    $this->pdo->prepare("DELETE FROM api_sessions WHERE card_code IN ($codePlaceholders)")->execute($codes);
                    $this->pdo->prepare("DELETE FROM active_devices WHERE card_code IN ($codePlaceholders)")->execute($codes); 
                } 
                $this->pdo->prepare("UPDATE cards SET device_hash = NULL WHERE id IN ($placeholders)")->execute($ids); 
                if ($own) $this->pdo->commit();
                return count($ids); 
            } catch (Throwable $e) { if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        }
        
        public function batchAddTime($ids, $hours) { 
            if (empty($ids) || $hours <= 0) return 0; 
            $seconds = intval($hours * 3600); 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $this->pdo->beginTransaction(); 
            try { 
                $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders) AND status = 1"); 
                $stmt->execute($ids); 
                $codes = $stmt->fetchAll(PDO::FETCH_COLUMN); 
                if($codes) { 
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?')); 
                    $this->pdo->prepare("UPDATE cards SET expire_time = DATE_ADD(expire_time, INTERVAL {$seconds} SECOND) WHERE id IN ($placeholders) AND status = 1")->execute($ids); 
                    $this->pdo->prepare("UPDATE active_devices SET expire_time = DATE_ADD(expire_time, INTERVAL {$seconds} SECOND) WHERE card_code IN ($codePlaceholders)")->execute($codes); 
                } 
                $this->pdo->commit(); 
                return count($codes); 
            } catch (Exception $e) { $this->pdo->rollBack(); return 0; } 
        }

        public function batchSubTime($ids, $hours) { 
            if (empty($ids) || $hours <= 0) return 0; 
            $seconds = intval($hours * 3600); 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $this->pdo->beginTransaction(); 
            try { 
                $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders) AND status = 1"); 
                $stmt->execute($ids); 
                $codes = $stmt->fetchAll(PDO::FETCH_COLUMN); 
                if($codes) { 
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?')); 
                    $this->pdo->prepare("UPDATE cards SET expire_time = DATE_SUB(expire_time, INTERVAL {$seconds} SECOND) WHERE id IN ($placeholders) AND status = 1")->execute($ids); 
                    $this->pdo->prepare("UPDATE active_devices SET expire_time = DATE_SUB(expire_time, INTERVAL {$seconds} SECOND) WHERE card_code IN ($codePlaceholders)")->execute($codes); 
                } 
                $this->pdo->commit(); 
                return count($codes); 
            } catch (Exception $e) { $this->pdo->rollBack(); return 0; } 
        }

        public function globalCompensate($hours, $appId = null) {
            $seconds = intval($hours * 3600);
            $sql = "UPDATE cards SET expire_time = DATE_ADD(expire_time, INTERVAL {$seconds} SECOND) WHERE status = 1 AND expire_time > NOW()";
            $params = [];
            if ($appId !== null) { $sql .= " AND app_id = ?"; $params[] = $appId; }
            $this->pdo->prepare($sql)->execute($params);

            $sql2 = "UPDATE active_devices SET expire_time = DATE_ADD(expire_time, INTERVAL {$seconds} SECOND) WHERE status = 1 AND expire_time > NOW()";
            $params2 = [];
            if ($appId !== null) { $sql2 .= " AND app_id = ?"; $params2[] = $appId; }
            $this->pdo->prepare($sql2)->execute($params2);
        }
        
        public function getCardsByIds($ids) { if(empty($ids)) return []; $placeholders = implode(',', array_fill(0, count($ids), '?')); $stmt = $this->pdo->prepare("SELECT * FROM cards WHERE id IN ($placeholders)"); $stmt->execute($ids); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
        public function resetDeviceBindingByCardId($id) { return $this->batchUnbindCards([$id]); }
        
        public function updateCardStatus($id, $status) { 
            if ($status == 1) { 
                $check = $this->pdo->prepare("SELECT expire_time FROM cards WHERE id = ?"); 
                $check->execute([$id]); 
                $row = $check->fetch(PDO::FETCH_ASSOC); 
                if ($row && empty($row['expire_time'])) { $status = 0; } 
            } 
            $this->pdo->prepare("UPDATE cards SET status=? WHERE id=?")->execute([$status, $id]); 
            if ($status == 2) { 
                $codeStmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id = ?"); 
                $codeStmt->execute([$id]); 
                $code = $codeStmt->fetchColumn(); 
                if ($code) { $this->pdo->prepare("DELETE FROM api_sessions WHERE card_code=?")->execute([$code]); $this->pdo->prepare("DELETE FROM active_devices WHERE card_code = ?")->execute([$code]); }
            } 
        }
        
        public function getDashboardData() { 
            $total = $this->pdo->query("SELECT COUNT(*) FROM cards WHERE app_id > 0")->fetchColumn(); 
            $unused = $this->pdo->query("SELECT COUNT(*) FROM cards WHERE status = 0 AND app_id > 0")->fetchColumn(); 
            $used = $this->pdo->query("SELECT COUNT(*) FROM cards WHERE status = 1 AND app_id > 0")->fetchColumn(); 
            $active = $this->pdo->query("SELECT COUNT(*) FROM active_devices WHERE status = 1 AND expire_time > NOW() AND app_id > 0")->fetchColumn(); 
            $online = $this->pdo->query("SELECT COUNT(*) FROM active_devices WHERE status = 1 AND expire_time > NOW() AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND app_id > 0")->fetchColumn(); 
            $types = $this->pdo->query("SELECT card_type, COUNT(*) as count FROM cards WHERE app_id > 0 GROUP BY card_type")->fetchAll(PDO::FETCH_KEY_PAIR); 
            $appStats = $this->pdo->query("SELECT T2.app_name, COUNT(T1.id) as count FROM cards T1 JOIN applications T2 ON T1.app_id = T2.id WHERE T1.app_id > 0 GROUP BY T1.app_id ORDER BY count DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); 
            return ['stats' => ['total' => $total, 'unused' => $unused, 'used' => $used, 'active' => $active, 'online' => $online], 'chart_types' => $types, 'app_stats' => $appStats]; 
        }
        
        public function getTotalCardCount($statusFilter = null, $appId = null, $typeFilter = null) {
            $sql = "SELECT COUNT(*) FROM cards WHERE app_id > 0";
            $params = [];
            if ($statusFilter !== null) { $sql .= " AND status = ?"; $params[] = $statusFilter; }
            if ($appId !== null) { $sql .= " AND app_id = ?"; $params[] = $appId; }
            if ($typeFilter !== null) { $sql .= " AND card_type = ?"; $params[] = $typeFilter; }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        }
        
        public function getCardsPaginated($limit, $offset, $statusFilter = null, $appId = null, $typeFilter = null, $sort = 'create_desc') {
            $sql = "SELECT T1.*, T2.app_name FROM cards T1 JOIN applications T2 ON T1.app_id = T2.id WHERE 1=1 ";
            if ($statusFilter !== null) $sql .= "AND T1.status = :status ";
            if ($appId !== null) $sql .= "AND T1.app_id = :app_id ";
            if ($typeFilter !== null) $sql .= "AND T1.card_type = :type ";
            
            if ($sort === 'expire_asc') $orderBy = "T1.expire_time ASC";
            elseif ($sort === 'expire_desc') $orderBy = "T1.expire_time DESC";
            else $orderBy = "T1.create_time DESC";
            
            $sql .= "ORDER BY $orderBy LIMIT :limit OFFSET :offset";
            
            $stmt = $this->pdo->prepare($sql);
            if ($statusFilter !== null) $stmt->bindValue(':status', $statusFilter, PDO::PARAM_INT);
            if ($appId !== null) $stmt->bindValue(':app_id', $appId, PDO::PARAM_INT);
            if ($typeFilter !== null) $stmt->bindValue(':type', $typeFilter, PDO::PARAM_STR);
            
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public function searchCards($k) { 
            $s="%$k%"; 
            $q=$this->pdo->prepare("SELECT T1.*, T2.app_name FROM cards T1 JOIN applications T2 ON T1.app_id = T2.id WHERE (T1.card_code LIKE ? OR T1.notes LIKE ? OR T1.device_hash LIKE ? OR T2.app_name LIKE ? OR T1.card_type LIKE ?) AND T1.app_id > 0"); 
            $q->execute([$s,$s,$s,$s,$s]); 
            return $q->fetchAll(PDO::FETCH_ASSOC); 
        }

        public function getUsageLogs($l, $o) { $q=$this->pdo->prepare("SELECT * FROM usage_logs ORDER BY access_time DESC LIMIT ? OFFSET ?"); $q->bindValue(1,$l,PDO::PARAM_INT); $q->bindValue(2,$o,PDO::PARAM_INT); $q->execute(); return $q->fetchAll(PDO::FETCH_ASSOC); }
        public function getActiveDevices() { return $this->pdo->query("SELECT T1.*, T2.app_name FROM active_devices T1 JOIN applications T2 ON T1.app_id = T2.id WHERE T1.status=1 AND T1.expire_time > NOW() AND T1.app_id > 0 ORDER BY T1.activate_time DESC")->fetchAll(PDO::FETCH_ASSOC); }
        
        public function generateCards($count, $type, $pre, $suf, $len, $note, $appId, $customDuration = 0) { 
            if ((int)$count<1 || (int)$count>1000 || strlen($pre.$suf)+$len>50 || (!isset(CARD_TYPES[$type]) && $type!=='custom')) throw new InvalidArgumentException("Invalid card parameters");
            if(empty($appId) || $appId <= 0) throw new Exception("必须指定有效的应用 ID");
            $own=!$this->pdo->inTransaction(); if ($own) $this->pdo->beginTransaction();
            $generatedCodes = [];
            try { 
                $stmt = $this->pdo->prepare("INSERT INTO cards (card_code, card_type, notes, app_id, duration) VALUES (?, ?, ?, ?, ?)"); 
                for ($i=0; $i<$count; $i++) { 
                    $code = $pre . $this->secureRandStr($len) . $suf; 
                    $stmt->execute([$code, $type, $note, $appId, $customDuration]); 
                    $generatedCodes[] = $code;
                } 
                if ($own) $this->pdo->commit();
                return $generatedCodes;
            } catch(Throwable $e) {
                if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e; 
            } 
        }

        public function deleteCard($id) { $this->batchDeleteCards([$id]); }
        public function getAdminHash() { return $this->pdo->query("SELECT password_hash FROM admin WHERE id=1")->fetchColumn(); }
        public function updateAdminPassword($pwd) { $this->pdo->prepare("UPDATE admin SET password_hash=? WHERE id=1")->execute([password_hash($pwd, PASSWORD_DEFAULT)]); }
        
        public function cleanupExpiredDevices() { $this->pdo->exec("UPDATE active_devices SET status=0 WHERE status=1 AND expire_time <= NOW()"); }
        private function logUsage($c, $t, $d, $i, $u, $r, $appName = 'System') { $this->pdo->prepare("INSERT INTO usage_logs (card_code, card_type, device_hash, ip_address, user_agent, result, app_name, access_time) VALUES (?,?,?,?,?,?,?,NOW())")->execute([$c,$t,$d,$i,$u,$r,$appName]); }
        
        private function secureRandStr($length) {
            $keyspace = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
            $str = '';
            $max = mb_strlen($keyspace, '8bit') - 1;
            for ($i = 0; $i < $length; ++$i) {
                $str .= $keyspace[random_int(0, $max)];
            }
            return $str;
        }

        public function exportAllDataStream($out) {
            $tables = ['applications', 'app_variables', 'cards', 'active_devices', 'usage_logs', 'blacklists', 'system_settings', 'admin'];
            fwrite($out, '{');
            $firstTable = true;
            foreach ($tables as $table) {
                if (!$firstTable) { fwrite($out, ','); }
                $firstTable = false;
                fwrite($out, '"' . $table . '":[');
                try {
                    $stmt = $this->pdo->query("SELECT * FROM `{$table}`");
                    $firstRow = true;
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!$firstRow) { fwrite($out, ','); }
                        $firstRow = false;
                        fwrite($out, json_encode($row, JSON_UNESCAPED_UNICODE));
                    }
                } catch (Exception $e) { }
                fwrite($out, ']');
            }
            fwrite($out, '}');
        }

        public function importAllData($data) {
            $tables=['applications','app_variables','cards','active_devices','usage_logs','blacklists','system_settings','admin'];
            if (!is_array($data) || array_diff($tables,array_keys($data)) || array_diff(array_keys($data),$tables)) throw new InvalidArgumentException('完整备份格式错误');
            $schema=[]; $total=0;
            foreach ($tables as $table) {
                $engine=$this->pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $engine->execute([$table]);
                if (strtoupper((string)$engine->fetchColumn())!=='INNODB') throw new RuntimeException('恢复仅支持事务型 InnoDB 表');
                $schema[$table]=$this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
                if (!is_array($data[$table]) || !array_is_list($data[$table])) throw new InvalidArgumentException('备份表格式错误');
                $total+=count($data[$table]); if ($total>100000) throw new InvalidArgumentException('备份记录过多');
                foreach ($data[$table] as $row) {
                    if (!is_array($row) || !$row || array_diff(array_keys($row),$schema[$table])) throw new InvalidArgumentException('备份含未知字段');
                    foreach ($row as $v) if (!is_scalar($v) && $v!==null) throw new InvalidArgumentException('备份字段类型错误');
                }
            }
            foreach ($data['applications'] as $app) {
                if (!preg_match('/^[a-f0-9]{64}$/D',$app['app_key'] ?? '') || !preg_match('/^[a-f0-9]{64}$/D',$app['api_secret'] ?? '') || $app['app_key']===$app['api_secret']) throw new InvalidArgumentException('备份应用密钥需先安全轮换');
            }
            if (count($data['admin'])!==1 || (int)($data['admin'][0]['id'] ?? 0)!==1 || empty(password_get_info($data['admin'][0]['password_hash'] ?? '')['algo'])) throw new InvalidArgumentException('备份管理员信息无效');
            $this->pdo->beginTransaction();
            try {
                // DML only: unlike TRUNCATE these writes can actually be rolled back.
                $this->pdo->exec('DELETE FROM api_sessions'); $this->pdo->exec('DELETE FROM api_nonces');
                foreach (array_reverse($tables) as $table) $this->pdo->exec("DELETE FROM `$table`");
                foreach ($tables as $table) foreach ($data[$table] as $row) {
                    $columns=array_keys($row); // Validated against trusted schema before any write.
                    $names=implode(',',array_map(static fn($c)=>"`$c`",$columns));
                    $marks=implode(',',array_fill(0,count($columns),'?'));
                    $this->pdo->prepare("INSERT INTO `$table` ($names) VALUES ($marks)")->execute(array_values($row));
                }
                if ($this->pdo->query('SELECT COUNT(*) FROM cards c LEFT JOIN applications a ON a.id=c.app_id WHERE a.id IS NULL')->fetchColumn() || $this->pdo->query('SELECT COUNT(*) FROM app_variables v LEFT JOIN applications a ON a.id=v.app_id WHERE a.id IS NULL')->fetchColumn()) throw new RuntimeException('备份应用关系无效');
                $this->pdo->commit(); return true;
            } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        }

    }
}
?>
