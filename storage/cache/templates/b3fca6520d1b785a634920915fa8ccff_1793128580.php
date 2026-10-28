<?php $this->extend('layouts/installer'); ?>
<?php $this->startSection('title'); ?> Ошибка установки <?php $this->stopSection(); ?>
<?php $this->startSection('content'); ?>
<h1>Ошибка установки</h1>
<div class="errbox">&#10060; <?php echo $this->e($reason); ?></div>
<form method="get" action="/install"><button>Попробовать снова</button></form>
<?php $this->stopSection(); ?>
