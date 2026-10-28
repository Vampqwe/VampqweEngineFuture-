<?php $this->extend('layouts/site'); ?>
<?php $this->startSection('title'); ?> <?php echo $this->e(appName); ?> <?php $this->stopSection(); ?>
<?php $this->startSection('content'); ?>
    <?php if ($dbOk): ?>
        <h1>✅ <?php echo $this->e(appName); ?></h1>
        <p>Движок успешно запущен! (Debug: <?php echo $this->e(debugLabel); ?>)</p>
    <?php else: ?>
        <h1>⚠️ <?php echo $this->e(appName); ?></h1>
        <p>Ядро работает, но база данных «<?php echo $this->e(dbDriver); ?>» недоступна. Проверьте запуск СУБД и настройки в .env.</p>
        <?php if ($debug && $dbError !== null): ?>
            <pre><?php echo $this->e($dbError); ?></pre>
        <?php endif; ?>
    <?php endif; ?>
<?php $this->stopSection(); ?>
