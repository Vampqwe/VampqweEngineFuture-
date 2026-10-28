<?php $this->extend('layouts/base'); ?>
<?php $this->startSection('title'); ?> 503 — <?php echo $this->e($title); ?> · <?php echo $this->e(appName); ?> <?php $this->stopSection(); ?>
<?php $this->startSection('content'); ?>
    <p class="code">503</p>
    <h1><?php echo $this->e($title); ?></h1>
    <p>Движок временно недоступен: идёт установка, обновление или техническое обслуживание. Загляните позже.</p>
<?php $this->stopSection(); ?>
