<?php $this->extend('layouts/installer'); ?>
<?php $this->startSection('title'); ?> Настройка движка <?php $this->stopSection(); ?>
<?php $this->startSection('content'); ?>
<h1>Настройка движка</h1>
<div class="sub">Шаг 2 из 3. Настройки можно поменять позже в <code>.env</code>.</div>
<?php if (!empty($errors): ?>)
    <div class="errbox"><?php echo (implode('<br>', array_map([\App\Core\Module\Installer\Internal\InstallerView::class, 'esc'], $errors))); ?></div>
<?php endif; ?>
<form method="post" action="/install/run">
    <label>Название сайта</label>
    <input name="app_name" value="<?php echo $this->e($appName); ?>" maxlength="64" required>
    <label>Пароль супер-администратора <small>(единственный управленец движка, логин зашит в ядро)</small></label>
    <input type="password" name="super_admin_password" minlength="8" autocomplete="new-password" required>
    <label>Часовой пояс</label>
    <select name="timezone"><?php foreach ($timezones as $tz): ?>
        <option value="<?php echo $this->e($tz); ?>"<?php if ($tz === $selectedTz): ?> selected <?php endif; ?>><?php echo $this->e($tz); ?></option><?php endforeach; ?>
    </select>
    <label style="display:flex;gap:8px;align-items:center;margin-top:14px">
        <input type="checkbox" name="debug" value="1" style="width:auto"> Режим отладки (показывать трейсы ошибок)</label>
    <button>Установить</button>
</form>
<?php $this->stopSection(); ?>
