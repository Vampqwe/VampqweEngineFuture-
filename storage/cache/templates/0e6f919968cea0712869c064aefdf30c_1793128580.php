<?php $this->extend('layouts/installer'); ?>
<?php $this->startSection('title'); ?> Установка завершена <?php $this->stopSection(); ?>
<?php $this->startSection('content'); ?>
<h1>Установка завершена</h1>
<div class="success">Движок установлен и готов к работе!</div>
<table>
<?php foreach ($summary as $k => $v): ?>
    <tr><td><?php echo $this->e((string) $k); ?></td><td><code><?php echo $this->e(is_bool($v) ? ($v ? 'да' : 'нет') : (string) $v); ?></code></td></tr>
<?php endforeach; ?>
</table>
<a href="/">&rarr; Открыть сайт</a>
<?php $this->stopSection(); ?>
