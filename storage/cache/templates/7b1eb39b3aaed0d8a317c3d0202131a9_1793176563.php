<div class="steps">
  <?php  $cur = (int) ($current ?? 1);  ?>
  <div class="st <?php echo $this->e($cur === 1 ? 'active' : ($cur > 1 ? 'done' : '')); ?>">1 · Окружение</div>
  <div class="st <?php echo $this->e($cur === 2 ? 'active' : ($cur > 2 ? 'done' : '')); ?>">2 · Настройки</div>
  <div class="st <?php echo $this->e($cur === 3 ? 'active' : ''); ?>">3 · Готово</div>
</div>
