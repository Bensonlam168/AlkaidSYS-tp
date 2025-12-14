<?php

declare(strict_types=1);

namespace addon\template;

use Infrastructure\Application\BaseApplication;
use think\facade\Db;

/**
 * Template Application | 模板应用
 *
 * This is a template for creating new applications.
 * Copy this directory and modify as needed.
 * 这是创建新应用的模板。
 * 复制此目录并根据需要修改。
 *
 * @package addon\template
 */
class Application extends BaseApplication
{
    /**
     * Application key | 应用标识
     */
    protected string $key = 'template';

    /**
     * Application name | 应用名称
     */
    protected string $name = 'Application Template';

    /**
     * Application version | 应用版本
     */
    protected string $version = '1.0.0';

    /**
     * Install the application | 安装应用
     *
     * @return void
     */
    public function install(): void
    {
        Db::startTrans();
        try {
            // 1. Execute SQL installation script
            if (file_exists($this->path . '/sql/install.sql')) {
                $this->executeSqlFile('sql/install.sql');
            }

            // 2. Install menus
            $this->installMenu('config/menu.php');

            // 3. Initialize configuration
            $this->initConfig();

            // 4. Record installation
            $this->recordInstallation();

            // 5. Trigger installation event
            $this->triggerEvent('ApplicationInstalled');

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * Uninstall the application | 卸载应用
     *
     * @param bool $keepData Whether to keep data | 是否保留数据
     * @return void
     */
    public function uninstall(bool $keepData = false): void
    {
        Db::startTrans();
        try {
            // 1. Execute SQL uninstallation script (if not keeping data)
            if (!$keepData && file_exists($this->path . '/sql/uninstall.sql')) {
                $this->executeSqlFile('sql/uninstall.sql');
            }

            // 2. Uninstall menus
            $this->uninstallMenu();

            // 3. Clear configuration
            $this->clearConfig();

            // 4. Remove installation record
            $this->removeInstallationRecord();

            // 5. Trigger uninstallation event
            $this->triggerEvent('ApplicationUninstalled', ['keep_data' => $keepData]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * Enable the application | 启用应用
     *
     * @return void
     */
    public function enable(): void
    {
        $this->updateStatus(1);
        $this->triggerEvent('ApplicationEnabled');
    }

    /**
     * Disable the application | 禁用应用
     *
     * @return void
     */
    public function disable(): void
    {
        $this->updateStatus(0);
        $this->triggerEvent('ApplicationDisabled');
    }

    /**
     * Upgrade the application | 升级应用
     *
     * @param string $fromVersion Current version | 当前版本
     * @param string $toVersion   Target version | 目标版本
     * @return void
     */
    public function upgrade(string $fromVersion, string $toVersion): void
    {
        Db::startTrans();
        try {
            // Execute upgrade script if exists
            $upgradeFile = "sql/upgrade/{$fromVersion}_to_{$toVersion}.sql";
            if (file_exists($this->path . '/' . $upgradeFile)) {
                $this->executeSqlFile($upgradeFile);
            }

            // Update version in database
            Db::name('applications')
                ->where('app_key', $this->key)
                ->update([
                    'version' => $toVersion,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            // Trigger upgrade event
            $this->triggerEvent('ApplicationUpgraded', [
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
}
