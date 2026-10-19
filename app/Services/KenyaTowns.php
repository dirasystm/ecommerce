<?php

declare(strict_types=1);

namespace Waimart\Services;

/**
 * Main towns of each of Kenya's 47 counties, for address suggestions at checkout and for placing pickup stations.
 * The first town of each county is its main commercial town (where bin/seed-kenya-coverage.php puts a station).
 * Suggestions only: customers can still type any town, and delivery is decided by the delivery zones.
 */
final class KenyaTowns
{
    /** county name (as in the `counties` table) => towns, main town first */
    public const BY_COUNTY = [
        'Mombasa' => ['Mombasa', 'Nyali', 'Bamburi', 'Kisauni', 'Likoni', 'Changamwe', 'Mtongwe'],
        'Kwale' => ['Ukunda', 'Kwale', 'Diani', 'Msambweni', 'Kinango', 'Lunga Lunga'],
        'Kilifi' => ['Kilifi', 'Malindi', 'Mtwapa', 'Watamu', 'Mariakani', 'Kaloleni'],
        'Tana River' => ['Hola', 'Garsen', 'Bura', 'Madogo'],
        'Lamu' => ['Lamu', 'Mpeketoni', 'Mokowe', 'Witu'],
        'Taita-Taveta' => ['Voi', 'Wundanyi', 'Mwatate', 'Taveta'],
        'Garissa' => ['Garissa', 'Dadaab', 'Masalani'],
        'Wajir' => ['Wajir', 'Habaswein', 'Bute'],
        'Mandera' => ['Mandera', 'El Wak', 'Takaba', 'Rhamu'],
        'Marsabit' => ['Marsabit', 'Moyale', 'Laisamis', 'North Horr'],
        'Isiolo' => ['Isiolo', 'Kinna', 'Garbatulla', 'Merti'],
        'Meru' => ['Meru', 'Maua', 'Nkubu', 'Timau', 'Laare'],
        'Tharaka-Nithi' => ['Chuka', 'Chogoria', 'Kathwana', 'Marimanti'],
        'Embu' => ['Embu', 'Runyenjes', 'Siakago', 'Kiritiri'],
        'Kitui' => ['Kitui', 'Mwingi', 'Mutomo', 'Kabati'],
        'Machakos' => ['Machakos', 'Athi River', 'Syokimau', 'Mlolongo', 'Kangundo', 'Tala', 'Matuu', 'Masii'],
        'Makueni' => ['Wote', 'Emali', 'Makindu', 'Kibwezi', 'Mtito Andei', 'Sultan Hamud'],
        'Nyandarua' => ['Ol Kalou', 'Engineer', 'Njabini', 'Ol Joro Orok'],
        'Nyeri' => ['Nyeri', 'Karatina', 'Othaya', 'Mukurweini', 'Naro Moru'],
        'Kirinyaga' => ['Kerugoya', 'Kutus', 'Sagana', 'Kagio', "Wang'uru"],
        "Murang'a" => ["Murang'a", 'Kenol', 'Maragua', 'Kangema', 'Kigumo', 'Kandara'],
        'Kiambu' => ['Thika', 'Kiambu', 'Ruiru', 'Juja', 'Limuru', 'Kikuyu', 'Githunguri', 'Karuri', 'Ruaka'],
        'Turkana' => ['Lodwar', 'Kakuma', 'Lokichar', 'Lokichoggio'],
        'West Pokot' => ['Kapenguria', 'Makutano', 'Chepareria', 'Sigor'],
        'Samburu' => ['Maralal', 'Baragoi', 'Archers Post', 'Wamba'],
        'Trans Nzoia' => ['Kitale', 'Kiminini', 'Endebess', 'Kwanza'],
        'Uasin Gishu' => ['Eldoret', 'Turbo', 'Burnt Forest', "Moi's Bridge", 'Ziwa'],
        'Elgeyo-Marakwet' => ['Iten', 'Kapsowar', 'Chepkorio'],
        'Nandi' => ['Kapsabet', 'Nandi Hills', 'Mosoriot', 'Kobujoi'],
        'Baringo' => ['Kabarnet', 'Eldama Ravine', 'Marigat', 'Mogotio', 'Chemolingot'],
        'Laikipia' => ['Nanyuki', 'Nyahururu', 'Rumuruti', 'Doldol'],
        'Nakuru' => ['Nakuru', 'Naivasha', 'Gilgil', 'Molo', 'Njoro', 'Subukia', 'Mai Mahiu'],
        'Narok' => ['Narok', 'Kilgoris', 'Ololulunga', 'Suswa'],
        'Kajiado' => ['Kitengela', 'Kajiado', 'Ongata Rongai', 'Ngong', 'Kiserian', 'Isinya', 'Namanga', 'Loitokitok'],
        'Kericho' => ['Kericho', 'Litein', 'Londiani', 'Kipkelion'],
        'Bomet' => ['Bomet', 'Sotik', 'Mulot', 'Longisa'],
        'Kakamega' => ['Kakamega', 'Mumias', 'Malava', 'Butere', 'Shinyalu'],
        'Vihiga' => ['Luanda', 'Mbale', 'Chavakali', 'Majengo'],
        'Bungoma' => ['Bungoma', 'Webuye', 'Kimilili', 'Chwele', 'Malakisi'],
        'Busia' => ['Busia', 'Malaba', 'Nambale', 'Port Victoria', 'Funyula'],
        'Siaya' => ['Siaya', 'Bondo', 'Ugunja', 'Yala', 'Usenge'],
        'Kisumu' => ['Kisumu', 'Ahero', 'Maseno', 'Muhoroni', 'Kombewa'],
        'Homa Bay' => ['Homa Bay', 'Oyugis', 'Mbita', 'Kendu Bay', 'Ndhiwa'],
        'Migori' => ['Migori', 'Rongo', 'Awendo', 'Isebania', 'Kehancha'],
        'Kisii' => ['Kisii', 'Ogembo', 'Suneka', 'Keroka', 'Nyamache'],
        'Nyamira' => ['Nyamira', 'Nyansiongo', 'Ekerenyo'],
        'Nairobi' => ['Nairobi CBD', 'Westlands', 'Kilimani', 'Karen', 'Langata', 'Parklands', 'Eastleigh', 'Embakasi', 'Kasarani', 'Donholm', 'South B', 'South C'],
    ];

    /** @return list<string> */
    public static function forCounty(string $county): array
    {
        return self::BY_COUNTY[$county] ?? [];
    }
}
