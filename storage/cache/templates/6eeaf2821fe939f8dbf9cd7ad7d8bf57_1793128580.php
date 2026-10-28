<?php $this->extend('layouts/installer'); ?>
<?php $this->startSection('title'); ?> Установка VampqweEngineFuture <?php $this->stopSection(); ?>
<?php $this->startSection('content'); ?>
<h1>Установка VampqweEngineFuture</h1>
<div class="sub">Проверка окружения перед установкой движка.</div>
<h2>Требования</h2>
<table>
<?php foreach ($rows as $row): ?>
    <tr><td><?php echo $this->e($row['label']); ?></td><td class="<?php echo $this->e($row['class']); ?>"><?php echo $this->e($row['icon']); ?> <?php echo $this->e($row['verdict']); ?></td></tr>
<?php endforeach; ?>
</table>
<?php if ($allOk): ?>
    <form method="post" action="/install/settings"><button>Перейти к настройкам &rarr;</button></form>
<?php else: ?>
    <div class="errbox">Устраните проблемы выше и обновите страницу.</div>
<?php endif; ?>
<?php $this->stopSection(); ?>
