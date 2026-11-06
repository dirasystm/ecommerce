<?php
/**
 * Multiple-photo chooser used on the new-product forms and the edit pages' Photos panel.
 * Photos are shown as previews before upload (public/assets/js/photo-picker.js); the first one becomes the main photo.
 * @var int $max   how many more photos may be added
 * @var bool $required
 * @var bool $isNew  true on the new-product form
 */
use Waimart\Core\Kernel;
use Waimart\Core\Session;

$max = (int) ($max ?? 10);
$isNew = !empty($isNew);
$hadErrors = $isNew && Session::errors() !== [];
?>
<div class="wm-photo-picker" data-photo-picker data-max="<?= $max ?>" data-max-mb="5" data-post-max-mb="<?= (int) floor(Kernel::iniBytes((string) ini_get('post_max_size')) / 1048576) ?>">
    <label class="wm-photo-drop<?= invalid('images') ?>" for="images">
        <i class="bi bi-camera" aria-hidden="true"></i>
        <span class="fw-semibold">Attach photos</span>
        <span class="small text-muted">Tap to choose, or drag photos here. Up to <?= $max ?> photo<?= $max === 1 ? '' : 's' ?> (JPEG, PNG or WebP). Large phone photos are made smaller automatically.</span>
    </label>
    <input class="wm-photo-input" type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple
           aria-describedby="imagesHelp"<?= !empty($required) ? ' required' : '' ?>>
    <?= field_error('images') ?>
    <?php if ($hadErrors): ?>
        <div class="alert alert-warning small py-2 mt-2 mb-0"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> For your security the browser forgets chosen photos when the form is sent back. Please attach your photos again.</div>
    <?php endif; ?>
    <div class="wm-photo-previews" data-photo-previews aria-live="polite"></div>
    <div class="form-text" id="imagesHelp">The first photo is the main one shown in the shop. Use the arrows to change the order. Clear, well-lit photos on a plain background sell best.</div>
</div>
