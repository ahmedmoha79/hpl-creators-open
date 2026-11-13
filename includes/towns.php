<?php
/**
 * Towns of Zambia (2026-11-10). The point of the clipping programme is reach across the whole country,
 * so every creator says which town they are in (one tap) and the team sees the spread in Admin > Analytics.
 * A phone's internet address is NOT used for this: Zambian mobile networks send most traffic out through
 * Lusaka, so the address says little about where the person really is.
 */
function zm_towns(): array
{
    return [
        'Lusaka' => ['Lusaka', 'Kafue', 'Chongwe', 'Chilanga', 'Luangwa'],
        'Copperbelt' => ['Kitwe', 'Ndola', 'Mufulira', 'Chingola', 'Luanshya', 'Kalulushi', 'Chililabombwe', 'Masaiti', 'Lufwanyama', 'Mpongwe'],
        'Central' => ['Kabwe', 'Kapiri Mposhi', 'Mkushi', 'Serenje', 'Mumbwa', 'Chibombo', 'Chisamba'],
        'Southern' => ['Livingstone', 'Choma', 'Mazabuka', 'Monze', 'Kalomo', 'Siavonga', 'Namwala', 'Sinazongwe'],
        'Eastern' => ['Chipata', 'Petauke', 'Katete', 'Lundazi', 'Nyimba', 'Chadiza', 'Mambwe'],
        'Northern' => ['Kasama', 'Mbala', 'Mpulungu', 'Luwingu', 'Mporokoso', 'Mungwi'],
        'Luapula' => ['Mansa', 'Samfya', 'Kawambwa', 'Nchelenge', 'Mwense'],
        'Muchinga' => ['Chinsali', 'Mpika', 'Nakonde', 'Isoka'],
        'North-Western' => ['Solwezi', 'Mwinilunga', 'Kasempa', 'Zambezi', 'Kabompo', 'Kalumbila'],
        'Western' => ['Mongu', 'Kaoma', 'Senanga', 'Sesheke', 'Kalabo', 'Lukulu'],
    ];
}

/** A town from the list exactly as we spell it, or '' ("Outside Zambia" and "Other town in Zambia" are allowed). */
function zm_town_clean(string $town): string
{
    $town = trim($town);
    if (in_array($town, ['Outside Zambia', 'Other town in Zambia'], true)) {
        return $town;
    }
    foreach (zm_towns() as $list) {
        foreach ($list as $t) {
            if (strcasecmp($t, $town) === 0) {
                return $t;
            }
        }
    }
    return '';
}

/** Province of a town ('' when unknown). */
function zm_province_of(string $town): string
{
    foreach (zm_towns() as $prov => $list) {
        if (in_array($town, $list, true)) {
            return $prov;
        }
    }
    return '';
}

/** <option>s for a town <select>. */
function zm_town_options(string $selected = ''): string
{
    $h = '<option value="">Choose your town</option>';
    foreach (zm_towns() as $prov => $list) {
        $h .= '<optgroup label="' . e($prov) . '">';
        foreach ($list as $t) {
            $h .= '<option' . ($t === $selected ? ' selected' : '') . '>' . e($t) . '</option>';
        }
        $h .= '</optgroup>';
    }
    foreach (['Other town in Zambia', 'Outside Zambia'] as $t) {
        $h .= '<option' . ($t === $selected ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    return $h;
}
