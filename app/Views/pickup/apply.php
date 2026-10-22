<?php
use Waimart\Services\PickupApplicationService as P;

/** @var list<array{id:int|string,name:string}> $counties @var array<int,list<string>> $towns @var int $stations */
$hoursOld = fv('hours', []);
$hoursOld = is_array($hoursOld) ? $hoursOld : [];
$days = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
$defaults = ['sun' => ['closed' => '1'], 'sat' => ['open' => '09:00', 'close' => '17:00']];
$day = function (string $k, string $f) use ($hoursOld, $defaults) {
    if ($hoursOld) {
        return (string) ($hoursOld[$k][$f] ?? '');
    }
    return (string) ($defaults[$k][$f] ?? ['open' => '08:00', 'close' => '18:00', 'closed' => ''][$f]);
};
$sel = (string) fv('county_id', '');
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">Become a pickup station</li>
        </ol>
    </nav>

    <section class="wm-card wm-page mb-3 wm-ps-hero">
        <div class="row g-4 align-items-center">
            <div class="col-lg-7">
                <span class="wm-ps-eyebrow"><i class="bi bi-shop" aria-hidden="true"></i> Partner programme</span>
                <h1 class="mt-2">Become a <?= e(brand_name()) ?> pickup station</h1>
                <p class="lead mb-3">Turn your shop into a collection point for <?= e(brand_name()) ?> orders. Customers in your area collect their parcels from you, and every visit is a chance to sell them something too.</p>
                <a class="btn btn-wm btn-lg" href="#apply"><i class="bi bi-pencil-square" aria-hidden="true"></i> Apply now</a>
                <a class="btn btn-outline-wm btn-lg ms-1" href="<?= e(url('/become-a-pickup-station/check')) ?>">Check an application</a>
                <p class="small text-muted mt-2 mb-0">Free to apply. Takes about 5 minutes. <?= $stations > 0 ? 'Join ' . number_format($stations) . ' stations across Kenya.' : '' ?></p>
            </div>
            <div class="col-lg-5">
                <div class="row g-2">
                    <?php foreach ([['people', 'More customers', 'People collecting parcels walk through your door every day.'],
                                    ['cash-coin', 'Extra income', 'Earn from parcel handling. Terms are agreed with you when you are approved.'],
                                    ['megaphone', 'Free visibility', 'Your shop is listed by name and location at checkout.'],
                                    ['headset', 'Training & support', 'We show you how to check codes and hand over parcels safely.']] as [$icon, $h, $t]): ?>
                        <div class="col-6"><div class="wm-ps-benefit"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><strong><?= e($h) ?></strong><span><?= e($t) ?></span></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <section class="wm-card wm-page h-100" aria-labelledby="howTitle">
                <h2 class="h5" id="howTitle">How it works</h2>
                <ol class="wm-ps-steps">
                    <li><strong>Apply</strong><span>Tell us about your business, location and opening hours.</span></li>
                    <li><strong>We verify</strong><span>Our team checks your details and may call or visit, usually within 3 working days.</span></li>
                    <li><strong>Get set up</strong><span>Once approved, your shop appears at checkout and we agree on parcel handover.</span></li>
                    <li><strong>Customers collect</strong><span>You check the customer's collection code and ID, then hand over the parcel.</span></li>
                </ol>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="wm-card wm-page h-100" aria-labelledby="needTitle">
                <h2 class="h5" id="needTitle">What you need</h2>
                <ul class="wm-ps-checklist">
                    <li>An existing, permanent business premises (not a home)</li>
                    <li>A lockable place to keep parcels safe</li>
                    <li>Open at least 5 days a week</li>
                    <li>Someone on duty to check codes and IDs</li>
                    <li>A phone number we can reach during opening hours</li>
                </ul>
            </section>
        </div>
    </div>

    <section class="wm-card wm-page mb-4" id="apply" aria-labelledby="applyTitle">
        <h2 class="h4" id="applyTitle">Application form</h2>
        <p class="small text-muted">Fields marked <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">with an asterisk</span> are required. We use these details only to assess your application and contact you about it.</p>
        <form method="post" action="<?= e(url('/become-a-pickup-station')) ?>" enctype="multipart/form-data" novalidate class="wm-ps-form">
            <?= csrf_field() ?>
            <div class="wm-hp" aria-hidden="true"><label for="website">Leave this empty</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>

            <fieldset class="wm-ps-section">
                <legend><span class="wm-step-num">1</span> Your business</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="business_name">Business name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control<?= invalid('business_name') ?>" id="business_name" name="business_name" maxlength="120" required autocomplete="organization" value="<?= e((string) fv('business_name', '')) ?>">
                        <?= field_error('business_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="business_type">Type of business <span class="text-danger" aria-hidden="true">*</span></label>
                        <select class="form-select<?= invalid('business_type') ?>" id="business_type" name="business_type" required>
                            <option value="">Choose…</option>
                            <?php foreach (P::BUSINESS_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= fv('business_type', '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                        <?= field_error('business_type') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="registration_number">Business registration or permit number</label>
                        <input class="form-control<?= invalid('registration_number') ?>" id="registration_number" name="registration_number" maxlength="60" aria-describedby="regHelp" value="<?= e((string) fv('registration_number', '')) ?>">
                        <div class="form-text" id="regHelp">Optional, but it speeds up verification (e.g. single business permit number).</div>
                        <?= field_error('registration_number') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="wm-ps-section">
                <legend><span class="wm-step-num">2</span> Contact person</legend>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="contact_name">Full name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control<?= invalid('contact_name') ?>" id="contact_name" name="contact_name" maxlength="120" required autocomplete="name" value="<?= e((string) fv('contact_name', '')) ?>">
                        <?= field_error('contact_name') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="phone">Phone number <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" type="tel" inputmode="tel" required autocomplete="tel" placeholder="0712 345 678" value="<?= e((string) fv('phone', '')) ?>">
                        <?= field_error('phone') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="email">Email address <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" required autocomplete="email" value="<?= e((string) fv('email', '')) ?>">
                        <?= field_error('email') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="wm-ps-section">
                <legend><span class="wm-step-num">3</span> Location</legend>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="ps_county">County <span class="text-danger" aria-hidden="true">*</span></label>
                        <select class="form-select<?= invalid('county_id') ?>" id="ps_county" name="county_id" required data-town-source>
                            <option value="">Choose your county…</option>
                            <?php foreach ($counties as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= $sel === (string) $c['id'] ? 'selected' : '' ?> data-towns="<?= e(implode('|', $towns[(int) $c['id']] ?? [])) ?>"><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error('county_id') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ps_town">Town or area <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control<?= invalid('town') ?>" id="ps_town" name="town" maxlength="100" required list="psTowns" autocomplete="off" placeholder="e.g. Nanyuki" value="<?= e((string) fv('town', '')) ?>">
                        <datalist id="psTowns" data-town-list></datalist>
                        <?= field_error('town') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="landmark">Nearby landmark</label>
                        <input class="form-control<?= invalid('landmark') ?>" id="landmark" name="landmark" maxlength="255" placeholder="e.g. Opposite Equity Bank" value="<?= e((string) fv('landmark', '')) ?>">
                        <?= field_error('landmark') ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="address">Street address and building <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control<?= invalid('address') ?>" id="address" name="address" maxlength="255" required placeholder="e.g. Kenyatta Avenue, Mt Kenya Plaza, Ground floor shop 4" value="<?= e((string) fv('address', '')) ?>">
                        <?= field_error('address') ?>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="w-100">
                            <button class="btn btn-outline-wm w-100" type="button" data-locate><i class="bi bi-geo-alt" aria-hidden="true"></i> Use my current location</button>
                            <input type="hidden" name="latitude" value="<?= e((string) fv('latitude', '')) ?>" data-lat>
                            <input type="hidden" name="longitude" value="<?= e((string) fv('longitude', '')) ?>" data-lng>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="small" role="status" aria-live="polite" data-locate-status><?= fv('latitude', '') !== '' ? 'Location saved.' : 'Tap the button while you are at the shop, so customers can find you on a map.' ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="map_url">Google Maps link</label>
                        <input class="form-control<?= invalid('map_url') ?>" id="map_url" name="map_url" type="url" maxlength="500" placeholder="https://maps.app.goo.gl/…" aria-describedby="mapHelp" value="<?= e((string) fv('map_url', '')) ?>">
                        <div class="form-text" id="mapHelp">Optional. In Google Maps, find your shop, tap Share and paste the link here.</div>
                        <?= field_error('map_url') ?>
                    </div>
                </div>
            </fieldset>

            <fieldset class="wm-ps-section" aria-describedby="hoursHelp">
                <legend><span class="wm-step-num">4</span> Opening hours <span class="text-danger" aria-hidden="true">*</span></legend>
                <p class="form-text mt-0" id="hoursHelp">When customers can collect parcels. Open at least 5 days a week.</p>
                <?= field_error('hours') ?>
                <div class="wm-ps-hours">
                    <?php foreach ($days as $k => $label): $closed = $day($k, 'closed') !== ''; ?>
                        <div class="wm-ps-day" data-day>
                            <span class="wm-ps-dayname" id="d_<?= $k ?>"><?= $label ?></span>
                            <label class="visually-hidden" for="h_<?= $k ?>_o"><?= $label ?> opening time</label>
                            <input class="form-control form-control-sm" type="time" id="h_<?= $k ?>_o" name="hours[<?= $k ?>][open]" value="<?= e($day($k, 'open') ?: '08:00') ?>" <?= $closed ? 'disabled' : '' ?>>
                            <span aria-hidden="true">to</span>
                            <label class="visually-hidden" for="h_<?= $k ?>_c"><?= $label ?> closing time</label>
                            <input class="form-control form-control-sm" type="time" id="h_<?= $k ?>_c" name="hours[<?= $k ?>][close]" value="<?= e($day($k, 'close') ?: '18:00') ?>" <?= $closed ? 'disabled' : '' ?>>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="h_<?= $k ?>_x" name="hours[<?= $k ?>][closed]" value="1" <?= $closed ? 'checked' : '' ?> data-day-closed>
                                <label class="form-check-label small" for="h_<?= $k ?>_x">Closed</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="wm-ps-section">
                <legend><span class="wm-step-num">5</span> Space and services</legend>
                <div class="row g-3">
                    <div class="col-md-7">
                        <span class="form-label d-block" id="storageLabel">Space for parcels <span class="text-danger" aria-hidden="true">*</span></span>
                        <div role="radiogroup" aria-labelledby="storageLabel">
                            <?php foreach (P::STORAGE as $k => $l): ?>
                                <div class="form-check">
                                    <input class="form-check-input<?= invalid('storage_space') ?>" type="radio" name="storage_space" id="st_<?= $k ?>" value="<?= $k ?>" <?= fv('storage_space', 'small') === $k ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="st_<?= $k ?>"><?= e($l) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?= field_error('storage_space') ?>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="daily_capacity">Parcels you could handle per day</label>
                        <input class="form-control<?= invalid('daily_capacity') ?>" id="daily_capacity" name="daily_capacity" inputmode="numeric" maxlength="4" placeholder="e.g. 25" value="<?= e((string) fv('daily_capacity', '')) ?>">
                        <?= field_error('daily_capacity') ?>
                    </div>
                    <div class="col-12">
                        <div class="form-check mb-2">
                            <input class="form-check-input<?= invalid('secure_storage') ?>" type="checkbox" id="secure_storage" name="secure_storage" value="1" <?= fchecked('secure_storage', false) ?> required>
                            <label class="form-check-label" for="secure_storage">I have a lockable place to keep parcels safe <span class="text-danger" aria-hidden="true">*</span></label>
                            <?= field_error('secure_storage') ?>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="accepts_cod" name="accepts_cod" value="1" <?= fchecked('accepts_cod', false) ?> aria-describedby="codHelp">
                            <label class="form-check-label" for="accepts_cod">We can collect cash payments for cash-on-delivery orders</label>
                            <div class="form-text" id="codHelp">Optional. Cash is then handed over to <?= e(brand_name()) ?> as agreed on approval.</div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="wm-ps-section">
                <legend><span class="wm-step-num">6</span> Photo and notes</legend>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="photo">Photo of your shop front</label>
                        <input class="form-control<?= invalid('photo') ?>" type="file" id="photo" name="photo" accept="image/jpeg,image/png" aria-describedby="photoHelp">
                        <div class="form-text" id="photoHelp">Optional. JPEG or PNG, up to 5 MB. Kept private and only seen by our team.</div>
                        <?= field_error('photo') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="notes">Anything else we should know?</label>
                        <textarea class="form-control<?= invalid('notes') ?>" id="notes" name="notes" rows="3" maxlength="1000" placeholder="e.g. how long you have been in business, parking, other services"><?= e((string) fv('notes', '')) ?></textarea>
                        <?= field_error('notes') ?>
                    </div>
                </div>
            </fieldset>

            <div class="form-check my-3">
                <input class="form-check-input<?= invalid('accept_terms') ?>" type="checkbox" id="accept_terms" name="accept_terms" value="1" required>
                <label class="form-check-label" for="accept_terms">The details above are correct, and <?= e(brand_name()) ?> may contact me or visit the premises to verify them. <span class="text-danger" aria-hidden="true">*</span></label>
                <?= field_error('accept_terms') ?>
            </div>
            <button class="btn btn-wm btn-lg" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Submit application</button>
            <p class="small text-muted mt-2 mb-0">You will get a reference number by email and SMS straight away.</p>
        </form>
    </section>
</div>
