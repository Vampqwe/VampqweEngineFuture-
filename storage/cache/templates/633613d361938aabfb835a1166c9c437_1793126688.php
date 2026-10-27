<?php $this->extend('layouts/base'); ?>
<?php $this->startSection('title'); ?> 404 — <?php echo $this->e($title); ?> · <?php echo $this->e(appName); ?> <?php $this->stopSection(); ?>
<?php $this->startSection('content'); ?>
    <p class="code">404</p>
    <h1><?php echo $this->e($title); ?></h1>
    <p>Страница не найдена. Возможно, она была перемещена или ещё не создана.</p>
    <p><a href="/">← На главную</a></p>
<?php $this->stopSection(); ?>
