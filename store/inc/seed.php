<?php
/**
 * Catalogue (version 2, 2026-11-04). What HPL really sells today: three Nokta gold detectors and
 * the HPL gold washer / sluice box. Prices in Zambian kwacha, as HPL publishes them (owner-confirmed).
 * Specs from Nokta's own product pages. The owner changes everything later in Admin > Products.
 *
 * store_seed() fills an empty catalogue. store_catalogue_v2() replaced the first catalogue on the
 * live site (old products kept but hidden, so old orders still show their names).
 */

function seed_j(array $a): string { return json_encode($a, JSON_UNESCAPED_UNICODE); }

function seed_categories(): array
{
    return [
        ['gold-detectors', 'detector', ['en' => 'Gold detectors', 'fr' => 'Détecteurs d\'or', 'sw' => 'Vigunduzi vya dhahabu'],
            ['en' => 'Nokta gold detectors, tested by our team in Zambian soil.', 'fr' => 'Détecteurs d\'or Nokta, testés par notre équipe dans le sol zambien.', 'sw' => 'Vigunduzi vya dhahabu vya Nokta, vimejaribiwa na timu yetu kwenye udongo wa Zambia.']],
        ['accessories', 'accessory', ['en' => 'Accessories', 'fr' => 'Accessoires', 'sw' => 'Vifaa vya ziada'],
            ['en' => 'Pinpointers, gold pans, scales and crucibles for every miner.', 'fr' => 'Pinpointers, batées, balances et creusets pour chaque mineur.', 'sw' => 'Pinpointer, sahani za dhahabu, mizani na vyungu vya kuyeyusha kwa kila mchimbaji.']],
        ['gold-washers', 'machine', ['en' => 'Gold washers and sluices', 'fr' => 'Laveuses d\'or et sluices', 'sw' => 'Mashine za kuosha dhahabu na sluice'],
            ['en' => 'Wash gravel and soil and keep the gold.', 'fr' => 'Lavez le gravier et la terre, gardez l\'or.', 'sw' => 'Osha kokoto na udongo, baki na dhahabu.']],
    ];
}

/** [slug, category, brand, name, price_zmw, stock, warranty, official_url, featured, image, gallery, tagline, description, best_for, specs, in_box] */
function seed_products(): array
{
    $P = [];

    $P[] = ['nokta-magnetar-9000', 'gold-detectors', 'Nokta', 'Magnetar 9000', 160000, 'in_stock', '3 years (Nokta)', 'https://magnetar.noktadetectors.com/', 1,
        'site/mg-main.jpg', ['assets/img/p-magnetar-shop.jpg', 'posters/tt-7651673765327211793.jpg', 'posters/tt-7684592491261185297.jpg', 'site/mg-bg2.jpg', 'site/mg-screen.jpg', 'site/mg-africa-13.jpg', 'site/mg-africa-10.jpg', 'site/mg-africa-6.jpg', 'site/mg-coil37.jpg'],
        ['en' => 'Nokta\'s pulse induction gold detector for deep nuggets in hard, mineralised ground.',
         'fr' => 'Le détecteur d\'or à induction pulsée de Nokta pour les pépites profondes en sol dur et minéralisé.',
         'sw' => 'Kigunduzi cha dhahabu cha Nokta cha pulse induction kwa vipande vilivyo chini sana kwenye udongo mgumu.'],
        ['en' => "The Magnetar 9000 is Nokta's first pulse induction (PI) gold detector and the most advanced machine we sell.\n\nPulse induction ignores most of the iron-rich, mineralised soil found in Zambia, Congo and West Africa. That is where ordinary detectors go quiet or make false signals. The Magnetar keeps going and reaches gold that is deeper in the ground.\n\nIt comes with two coils: a large 37 cm coil to cover ground and go deep, and a smaller 29 cm coil for tight places and old pits. It has a colour screen, Easy and Expert modes, and it is waterproof to 3 metres, so you can work riverbanks and shallow water. Two batteries come in the box, about 20 hours of work in total.\n\nWe show you how to use it before you leave the shop, and we keep helping you by phone and WhatsApp after you buy.",
         'fr' => "Le Magnetar 9000 est le premier détecteur d'or à induction pulsée (PI) de Nokta et la machine la plus avancée que nous vendons.\n\nL'induction pulsée ignore la plupart des sols minéralisés riches en fer que l'on trouve en Zambie, au Congo et en Afrique de l'Ouest. Là où les détecteurs ordinaires se taisent ou donnent de faux signaux, le Magnetar continue et atteint l'or plus profond.\n\nIl est livré avec deux disques : un grand disque de 37 cm pour couvrir le terrain et aller en profondeur, et un disque de 29 cm pour les endroits étroits et les anciens puits. Écran couleur, modes Easy et Expert, étanche jusqu'à 3 mètres : vous pouvez prospecter les berges et l'eau peu profonde. Deux batteries sont incluses, environ 20 heures de travail.\n\nNous vous montrons comment l'utiliser avant votre départ et nous continuons à vous aider par téléphone et WhatsApp après l'achat.",
         'sw' => "Magnetar 9000 ni kigunduzi cha kwanza cha Nokta cha pulse induction (PI) na mashine bora zaidi tunayouza.\n\nPulse induction haisumbuliwi na udongo wenye madini ya chuma unaopatikana Zambia, Kongo na Afrika Magharibi. Pale ambapo vigunduzi vya kawaida vinanyamaza au kutoa sauti za uongo, Magnetar inaendelea na kufikia dhahabu iliyo chini zaidi.\n\nInakuja na koili mbili: koili kubwa ya sentimita 37 kufunika eneo kubwa na kwenda chini, na koili ya sentimita 29 kwa sehemu nyembamba na mashimo ya zamani. Ina skrini ya rangi, njia za Easy na Expert, na haiingii maji hadi mita 3. Betri mbili ziko ndani ya boksi, takriban saa 20 za kazi.\n\nTunakufundisha kuitumia kabla ya kuondoka dukani, na tunaendelea kukusaidia kwa simu na WhatsApp baada ya kununua."],
        ['en' => 'Serious prospectors, deep nuggets, hard and mineralised ground', 'fr' => 'Prospecteurs sérieux, pépites profondes, sols durs et minéralisés', 'sw' => 'Wachimbaji makini, dhahabu iliyo chini sana, udongo mgumu'],
        ['en' => [['Technology', 'Pulse induction (PI)'], ['Coils included', 'MG37 DRC (37 x 34 cm) and MG29 DRC (29 x 21 cm)'], ['Search modes', 'Easy and Expert'], ['Waterproof', 'IP68, to 3 m'], ['Weight', '2.4 kg (small coil), 2.8 kg (large coil), with battery'], ['Battery', '7.2 V 48 Wh lithium-ion, up to 10 hours each, 2 included'], ['Length', '72 cm folded, 150 cm open, carbon fibre shaft'], ['Screen and sound', 'Colour screen, Bluetooth audio, vibration, flashlight'], ['Maker\'s warranty', '3 years (Nokta)']],
         'fr' => [['Technologie', 'Induction pulsée (PI)'], ['Disques inclus', 'MG37 DRC (37 x 34 cm) et MG29 DRC (29 x 21 cm)'], ['Modes', 'Easy et Expert'], ['Étanche', 'IP68, jusqu\'à 3 m'], ['Poids', '2,4 kg (petit disque), 2,8 kg (grand disque), batterie comprise'], ['Batterie', 'Lithium-ion 7,2 V 48 Wh, jusqu\'à 10 heures chacune, 2 incluses'], ['Longueur', '72 cm plié, 150 cm ouvert, tige en fibre de carbone'], ['Écran et son', 'Écran couleur, audio Bluetooth, vibration, lampe'], ['Garantie du fabricant', '3 ans (Nokta)']],
         'sw' => [['Teknolojia', 'Pulse induction (PI)'], ['Koili', 'MG37 DRC (37 x 34 cm) na MG29 DRC (29 x 21 cm)'], ['Njia za kutafuta', 'Easy na Expert'], ['Maji', 'IP68, hadi mita 3'], ['Uzito', 'Kilo 2.4 (koili ndogo), kilo 2.8 (koili kubwa), pamoja na betri'], ['Betri', 'Lithium-ion 7.2 V 48 Wh, hadi saa 10 kila moja, 2 ndani'], ['Urefu', 'Sm 72 ikikunjwa, sm 150 ikifunguliwa'], ['Skrini na sauti', 'Skrini ya rangi, Bluetooth, mtetemo, tochi'], ['Dhamana ya mtengenezaji', 'Miaka 3 (Nokta)']]],
        ['en' => ['Magnetar 9000 control box and carbon fibre shaft', 'MG37 DRC and MG29 DRC coils', '2 lithium-ion batteries and charger', 'Backpack', 'Digger'],
         'fr' => ['Boîtier Magnetar 9000 et tige en fibre de carbone', 'Disques MG37 DRC et MG29 DRC', '2 batteries lithium-ion et chargeur', 'Sac à dos', 'Pioche'],
         'sw' => ['Kifaa cha Magnetar 9000 na fimbo ya carbon', 'Koili MG37 DRC na MG29 DRC', 'Betri 2 za lithium-ion na chaja', 'Begi la mgongoni', 'Kifaa cha kuchimba']]];

    $P[] = ['nokta-gold-finder-2000', 'gold-detectors', 'Nokta', 'Gold Finder 2000', 60000, 'in_stock', '2 years (Nokta)', 'https://www.noktadetectors.com/metal-detector/gold-finder-2000/', 1,
        'site/gf-1.jpg', ['assets/img/p-goldfinder-shop.jpg', 'posters/tt-7616779131316899088.jpg', 'site/gf-3.jpg', 'site/gf-4.jpg', 'site/gf-2.jpg', 'site/gf-box.jpg'],
        ['en' => 'Simple 61 kHz gold detector that picks up small gold. Easy for first-time buyers.',
         'fr' => 'Détecteur d\'or simple de 61 kHz qui capte l\'or fin. Facile pour un premier achat.',
         'sw' => 'Kigunduzi rahisi cha 61 kHz kinachopata dhahabu ndogo. Rahisi kwa anayeanza.'],
        ['en' => "The Gold Finder 2000 is easy to learn: switch it on, let it balance to the ground by itself, and start searching. It has two modes, All Metal and Discrimination, so you can tell gold from iron rubbish before you dig.\n\nIt runs at 61 kHz, a high frequency that is very sensitive to small gold near the surface. It comes with two coils, a 26 cm coil for normal searching and a 13 cm coil for small gold in tight places. It has a pinpoint button, a built-in light and a wireless speaker.\n\nThe battery charges by USB, so you can top it up from a power bank or solar panel in the bush. The coils are waterproof; the control box is rain-proof but should not go under water.",
         'fr' => "Le Gold Finder 2000 est facile à apprendre : allumez-le, il s'équilibre seul au sol, et commencez à chercher. Il a deux modes, Tous métaux et Discrimination, pour distinguer l'or des déchets de fer avant de creuser.\n\nIl fonctionne à 61 kHz, une haute fréquence très sensible à l'or fin près de la surface. Il est livré avec deux disques : 26 cm pour la recherche normale et 13 cm pour l'or fin dans les endroits étroits. Bouton pinpoint, lampe intégrée et haut-parleur sans fil.\n\nLa batterie se recharge par USB, avec une batterie externe ou un panneau solaire en brousse. Les disques sont étanches ; le boîtier résiste à la pluie mais ne doit pas aller sous l'eau.",
         'sw' => "Gold Finder 2000 ni rahisi kujifunza: iwashe, inajipima na udongo yenyewe, anza kutafuta. Ina njia mbili, All Metal na Discrimination, ili utambue dhahabu na takataka za chuma kabla ya kuchimba.\n\nInafanya kazi kwa 61 kHz, masafa ya juu yanayohisi dhahabu ndogo karibu na juu ya ardhi. Inakuja na koili mbili: sm 26 kwa utafutaji wa kawaida na sm 13 kwa dhahabu ndogo sehemu nyembamba. Ina kitufe cha pinpoint, tochi na spika isiyo na waya.\n\nBetri inachajiwa kwa USB, hata kwa power bank au sola porini. Koili haziingii maji; kifaa chenyewe kinavumilia mvua lakini kisizamishwe majini."],
        ['en' => 'First detector, small surface gold, budget buyers', 'fr' => 'Premier détecteur, or fin en surface, petit budget', 'sw' => 'Kigunduzi cha kwanza, dhahabu ndogo ya juu, bajeti ndogo'],
        ['en' => [['Technology', 'VLF, 61 kHz'], ['Coils included', 'GK26C (26 x 14 cm) and GF13 (13 cm DD), both waterproof'], ['Search modes', 'All Metal and Discrimination'], ['Ground balance', 'Fully automatic'], ['Water', 'Coils waterproof, control box rain-proof'], ['Weight', '1.7 kg with coil'], ['Battery', '3700 mAh lithium-polymer, USB charging'], ['Length', '76 to 136 cm'], ['Extras', 'Pinpoint, LED light, 2.4 GHz wireless speaker'], ['Maker\'s warranty', '2 years (Nokta)']],
         'fr' => [['Technologie', 'VLF, 61 kHz'], ['Disques inclus', 'GK26C (26 x 14 cm) et GF13 (13 cm DD), étanches'], ['Modes', 'Tous métaux et Discrimination'], ['Équilibrage du sol', 'Entièrement automatique'], ['Eau', 'Disques étanches, boîtier résistant à la pluie'], ['Poids', '1,7 kg avec disque'], ['Batterie', 'Lithium-polymère 3700 mAh, charge USB'], ['Longueur', '76 à 136 cm'], ['Autres', 'Pinpoint, lampe LED, haut-parleur sans fil 2,4 GHz'], ['Garantie du fabricant', '2 ans (Nokta)']],
         'sw' => [['Teknolojia', 'VLF, 61 kHz'], ['Koili', 'GK26C (26 x 14 cm) na GF13 (13 cm DD), haziingii maji'], ['Njia za kutafuta', 'All Metal na Discrimination'], ['Kupima udongo', 'Yenyewe (automatic)'], ['Maji', 'Koili haziingii maji, kifaa kinavumilia mvua'], ['Uzito', 'Kilo 1.7 pamoja na koili'], ['Betri', '3700 mAh, kuchaji kwa USB'], ['Urefu', 'Sm 76 hadi 136'], ['Ziada', 'Pinpoint, tochi ya LED, spika isiyo na waya'], ['Dhamana ya mtengenezaji', 'Miaka 2 (Nokta)']]],
        ['en' => ['Gold Finder 2000 detector', 'GK26C and GF13 coils', 'Wireless speaker', 'USB charging cable', 'Coil cover'],
         'fr' => ['Détecteur Gold Finder 2000', 'Disques GK26C et GF13', 'Haut-parleur sans fil', 'Câble de charge USB', 'Protège-disque'],
         'sw' => ['Kigunduzi cha Gold Finder 2000', 'Koili GK26C na GF13', 'Spika isiyo na waya', 'Waya ya kuchaji USB', 'Kifuniko cha koili']]];

    $P[] = ['nokta-gold-kruzer', 'gold-detectors', 'Nokta', 'Gold Kruzer', 30000, 'in_stock', '2 years (Nokta)', 'https://www.noktadetectors.com/metal-detector/gold-kruzer/', 1,
        'site/gk-1.jpg', ['posters/tt-7612711794112515329.jpg', 'site/gk-4.jpg', 'site/gk-2.jpg', 'site/gk-out3.jpg', 'site/gk-box.jpg'],
        ['en' => 'Fully waterproof 61 kHz gold detector for alluvial gold, rivers and small nuggets.',
         'fr' => 'Détecteur d\'or 61 kHz entièrement étanche pour l\'or alluvionnaire, les rivières et les petites pépites.',
         'sw' => 'Kigunduzi cha 61 kHz kisichoingia maji kabisa, kwa dhahabu ya mto na vipande vidogo.'],
        ['en' => "The Gold Kruzer is light, strong and fully waterproof to 5 metres, so you can use it in rivers, streams and rain.\n\nIt runs at 61 kHz, which is very sensitive to small gold. It has four search modes (General, Fast, Boost and Micro) and you can balance it to the ground automatically, by hand or let it track the ground while you walk. The screen shows a target number from 00 to 99, so you learn to recognise gold before you dig.\n\nIt comes with two coils and a built-in battery that lasts up to 19 hours. Many of our customers pair it with a sluice box for alluvial work.",
         'fr' => "Le Gold Kruzer est léger, solide et entièrement étanche jusqu'à 5 mètres : vous pouvez l'utiliser dans les rivières, les ruisseaux et sous la pluie.\n\nIl fonctionne à 61 kHz, très sensible à l'or fin. Il a quatre modes (Général, Rapide, Boost et Micro) et un équilibrage du sol automatique, manuel ou en suivi pendant la marche. L'écran affiche un numéro de cible de 00 à 99 pour reconnaître l'or avant de creuser.\n\nIl est livré avec deux disques et une batterie intégrée qui dure jusqu'à 19 heures. Beaucoup de nos clients l'associent à un sluice pour l'or alluvionnaire.",
         'sw' => "Gold Kruzer ni nyepesi, imara na haiingii maji hadi mita 5, hivyo unaweza kuitumia mtoni, kijitoni na kwenye mvua.\n\nInafanya kazi kwa 61 kHz, inayohisi dhahabu ndogo sana. Ina njia nne (General, Fast, Boost na Micro) na inapima udongo yenyewe, kwa mkono au ikifuatilia udongo unapotembea. Skrini inaonyesha namba ya kitu kutoka 00 hadi 99 ili utambue dhahabu kabla ya kuchimba.\n\nInakuja na koili mbili na betri inayodumu hadi saa 19. Wateja wetu wengi huitumia pamoja na sluice kwa dhahabu ya mto."],
        ['en' => 'Rivers and alluvial gold, small nuggets, wet season', 'fr' => 'Rivières et or alluvionnaire, petites pépites, saison des pluies', 'sw' => 'Mito na dhahabu ya mto, vipande vidogo, msimu wa mvua'],
        ['en' => [['Technology', 'VLF, 61 kHz'], ['Coils included', 'GK26C (26 x 14 cm concentric) and GK19 (19 x 10 cm DD)'], ['Search modes', 'General, Fast, Boost, Micro'], ['Ground balance', 'Automatic, manual and tracking'], ['Waterproof', 'IP68, to 5 m'], ['Weight', '1.4 kg with coil and battery'], ['Battery', '3700 mAh lithium-polymer, up to 19 hours'], ['Length', '111 to 135.5 cm'], ['Screen', 'Backlit, target ID 00 to 99'], ['Maker\'s warranty', '2 years (Nokta)']],
         'fr' => [['Technologie', 'VLF, 61 kHz'], ['Disques inclus', 'GK26C (26 x 14 cm concentrique) et GK19 (19 x 10 cm DD)'], ['Modes', 'Général, Rapide, Boost, Micro'], ['Équilibrage du sol', 'Automatique, manuel et suivi'], ['Étanche', 'IP68, jusqu\'à 5 m'], ['Poids', '1,4 kg avec disque et batterie'], ['Batterie', 'Lithium-polymère 3700 mAh, jusqu\'à 19 heures'], ['Longueur', '111 à 135,5 cm'], ['Écran', 'Rétroéclairé, identification 00 à 99'], ['Garantie du fabricant', '2 ans (Nokta)']],
         'sw' => [['Teknolojia', 'VLF, 61 kHz'], ['Koili', 'GK26C (26 x 14 cm) na GK19 (19 x 10 cm DD)'], ['Njia za kutafuta', 'General, Fast, Boost, Micro'], ['Kupima udongo', 'Yenyewe, kwa mkono na kufuatilia'], ['Maji', 'IP68, hadi mita 5'], ['Uzito', 'Kilo 1.4 pamoja na koili na betri'], ['Betri', '3700 mAh, hadi saa 19'], ['Urefu', 'Sm 111 hadi 135.5'], ['Skrini', 'Yenye mwanga, namba 00 hadi 99'], ['Dhamana ya mtengenezaji', 'Miaka 2 (Nokta)']]],
        ['en' => ['Gold Kruzer detector', 'GK26C and GK19 coils', 'Charging cable', 'Coil covers'],
         'fr' => ['Détecteur Gold Kruzer', 'Disques GK26C et GK19', 'Câble de charge', 'Protège-disques'],
         'sw' => ['Kigunduzi cha Gold Kruzer', 'Koili GK26C na GK19', 'Waya ya kuchaji', 'Vifuniko vya koili']]];

    $P[] = ['hpl-sluice-wash-plant', 'gold-washers', 'HPL', 'Sluice box wash plant', 60000, 'low', '', '', 1,
        'posters/tt-7696069634390330644.jpg', ['posters/tt-7703536612457729300.jpg', 'posters/tt-7697551094301347092.jpg', 'posters/tt-7698763514797673780.jpg'],
        ['en' => 'Blue sluice box wash plant with water pump, pipes and gold mat. Wash more gravel and keep the fine gold.',
         'fr' => 'Laverie à sluice bleue avec pompe à eau, tuyaux et tapis à or. Lavez plus de gravier et gardez l\'or fin.',
         'sw' => 'Mashine ya kuosha ya sluice ya bluu yenye pampu ya maji, mabomba na mkeka wa dhahabu. Osha kokoto nyingi na ubaki na dhahabu ndogo.'],
        ['en' => "Panning by hand is slow and loses the fine gold. The HPL sluice box wash plant lets the machine do the work: the pump pushes water over your gravel and soil, the light material is washed away and the heavy gold stays in the gold mat.\n\nIt comes complete with a water pump, pipes and the gold mat, ready to set up next to your water source. It is built for small-scale miners and prospectors who want to process more material in a day and stop losing fine gold.\n\nPair it with a gold detector: use the detector to find where the gold is, and the wash plant to recover it from the soil and gravel.\n\nAsk us for the price, delivery to your site and how to set it up; we answer with photos and videos of the machine.",
         'fr' => "Le lavage à la batée est lent et perd l'or fin. La laverie à sluice HPL fait le travail à votre place : la pompe envoie l'eau sur votre gravier et votre terre, les matériaux légers partent et l'or lourd reste dans le tapis à or.\n\nElle est livrée complète avec une pompe à eau, des tuyaux et le tapis à or, prête à installer près de votre point d'eau. Elle est faite pour les petits exploitants et prospecteurs qui veulent traiter plus de matériau par jour et ne plus perdre l'or fin.\n\nAssociez-la à un détecteur : le détecteur trouve où est l'or, la laverie le récupère dans la terre et le gravier.\n\nDemandez-nous le prix, la livraison sur votre site et l'installation ; nous répondons avec des photos et des vidéos de la machine.",
         'sw' => "Kuosha kwa sahani ni polepole na kunapoteza dhahabu ndogo. Mashine ya sluice ya HPL inafanya kazi badala yako: pampu inasukuma maji juu ya kokoto na udongo wako, vitu vyepesi vinaondoka na dhahabu nzito inabaki kwenye mkeka wa dhahabu.\n\nInakuja kamili na pampu ya maji, mabomba na mkeka wa dhahabu, tayari kufungwa karibu na chanzo chako cha maji. Imetengenezwa kwa wachimbaji wadogo wanaotaka kuosha zaidi kwa siku na kuacha kupoteza dhahabu ndogo.\n\nIunganishe na kigunduzi: kigunduzi kinaonyesha dhahabu ilipo, mashine inaipata kutoka kwenye udongo na kokoto.\n\nTuulize bei, usafirishaji hadi eneo lako na jinsi ya kuifunga; tunajibu kwa picha na video za mashine."],
        ['en' => 'Small-scale miners processing gravel and soil, alluvial gold', 'fr' => 'Petits exploitants qui traitent gravier et terre, or alluvionnaire', 'sw' => 'Wachimbaji wadogo wanaoosha kokoto na udongo, dhahabu ya mto'],
        [], ['en' => ['Sluice box wash plant', 'Water pump', 'Pipes', 'Gold mat'], 'fr' => ['Laverie à sluice', 'Pompe à eau', 'Tuyaux', 'Tapis à or'], 'sw' => ['Mashine ya sluice', 'Pampu ya maji', 'Mabomba', 'Mkeka wa dhahabu']]];

    $acc = function (string $slug, string $name, int $price, string $img, array $tag, array $desc, string $credit) {
        return [$slug, 'accessories', '', $name, $price, 'in_stock', '', '', 0, $img, [], $tag, $desc, ['en' => '', 'fr' => '', 'sw' => ''], [], [], $credit];
    };
    $P[] = $acc('pinpointer', 'Pinpointer', 1500, 'site/acc-pinpointer.jpg',
        ['en' => 'Finds the exact spot of the target in the hole, so you dig less and find more.', 'fr' => 'Trouve l\'endroit exact de la cible dans le trou : vous creusez moins et trouvez plus.', 'sw' => 'Inapata mahali hasa pa kitu ndani ya shimo, unachimba kidogo na kupata zaidi.'],
        ['en' => "When your detector gives a signal, the pinpointer finds the exact spot in the hole or in the soil you took out. It beeps and vibrates as it gets closer, so you stop wasting time digging big holes or losing small pieces in the soil.\n\nWorks with any gold detector.", 'fr' => "Quand votre détecteur donne un signal, le pinpointer trouve l'endroit exact dans le trou ou dans la terre sortie. Il bipe et vibre à l'approche : vous ne perdez plus de temps à creuser de grands trous ni de petites pièces dans la terre.\n\nFonctionne avec tout détecteur d'or.", 'sw' => "Kigunduzi chako kikitoa ishara, pinpointer inapata mahali hasa ndani ya shimo au kwenye udongo uliotoa. Inalia na kutetemeka inapokaribia, hivyo huchimbi mashimo makubwa wala kupoteza vipande vidogo kwenye udongo.\n\nInafanya kazi na kigunduzi chochote cha dhahabu."], 'public domain photo');
    $P[] = $acc('gold-scale', 'Gold scale', 500, 'site/acc-scale.jpg',
        ['en' => 'Digital pocket scale to weigh your gold in grams before you sell.', 'fr' => 'Balance de poche numérique pour peser votre or en grammes avant de vendre.', 'sw' => 'Mizani ndogo ya kidijitali ya kupima dhahabu yako kwa gramu kabla ya kuuza.'],
        ['en' => "Know exactly how much gold you have before you go to a buyer. This small digital scale weighs in grams and fits in your pocket.", 'fr' => "Sachez exactement combien d'or vous avez avant d'aller voir un acheteur. Cette petite balance numérique pèse en grammes et tient dans la poche.", 'sw' => "Jua kiasi kamili cha dhahabu uliyonayo kabla ya kwenda kwa mnunuzi. Mizani hii ndogo ya kidijitali inapima kwa gramu na inaingia mfukoni."], 'Photo: TBBSC, CC BY-SA 4.0, Wikimedia Commons');
    $P[] = $acc('gold-pan', 'Gold pan', 700, 'site/acc-goldpan.jpg',
        ['en' => 'Pan for washing out gold from soil, gravel and concentrates.', 'fr' => 'Batée pour récupérer l\'or de la terre, du gravier et des concentrés.', 'sw' => 'Sahani ya kuosha dhahabu kutoka udongo, kokoto na mabaki.'],
        ['en' => "The simplest gold tool there is. Use it to test a new spot, to finish the concentrate from your sluice or wash plant, and to recover the small gold a detector cannot hear.", 'fr' => "L'outil d'or le plus simple. Pour tester un nouvel endroit, finir le concentré de votre sluice ou laverie et récupérer l'or fin qu'un détecteur n'entend pas.", 'sw' => "Kifaa rahisi kabisa cha dhahabu. Kitumie kujaribu eneo jipya, kumalizia mabaki ya sluice au mashine yako, na kupata dhahabu ndogo ambayo kigunduzi hakisikii."], 'Photo: Nate Cull, CC BY 2.0, Wikimedia Commons');
    $P[] = $acc('crucible-500g', 'Crucible 500 g', 600, 'site/acc-crucible.jpg',
        ['en' => 'Melting crucible that takes up to 500 g, for turning your gold into a bar.', 'fr' => 'Creuset de fonte jusqu\'à 500 g, pour transformer votre or en lingot.', 'sw' => 'Chungu cha kuyeyusha hadi gramu 500, kugeuza dhahabu yako kuwa kipande kimoja.'],
        ['en' => "A crucible for melting gold, holding up to 500 grams. Ask us which furnace or torch it works with.", 'fr' => "Un creuset pour fondre l'or, jusqu'à 500 grammes. Demandez-nous avec quel four ou chalumeau l'utiliser.", 'sw' => "Chungu cha kuyeyusha dhahabu, kinabeba hadi gramu 500. Tuulize kinafanya kazi na tanuru au moto gani."], 'public domain photo');

    return $P;
}

/** [tiktok id, sections, product slug, en, fr, sw, place, account] — HPL's own TikTok videos, hosted on this site. */
function seed_videos(): array
{
    return [
        ['7575991994321767692', 'home', '', 'Zambia is rich in gold. Start with the right detector', 'La Zambie est riche en or. Commencez avec le bon détecteur', 'Zambia ina dhahabu nyingi. Anza na kigunduzi sahihi', 'Zambia'],
        ['7681565447564610833', 'home,field', 'nokta-magnetar-9000', 'Magnetar 9000 in real field action', 'Magnetar 9000 en action sur le terrain', 'Magnetar 9000 ikifanya kazi shambani', 'Zambia'],
        ['7674675897559026961', 'field', 'nokta-magnetar-9000', 'Magnetar 9000 in pits other miners already dug', 'Magnetar 9000 dans des puits déjà creusés', 'Magnetar 9000 kwenye mashimo yaliyochimbwa tayari', 'Zambia'],
        ['7616779131316899088', 'field', 'nokta-gold-finder-2000', 'Gold Finder 2000 going deeper', 'Le Gold Finder 2000 va plus profond', 'Gold Finder 2000 ikienda chini zaidi', 'Zambia'],
        ['7640176685345705217', 'field', 'nokta-gold-finder-2000', 'A day in the field with the Gold Finder 2000', 'Une journée sur le terrain avec le Gold Finder 2000', 'Siku shambani na Gold Finder 2000', 'Zambia'],
        ['7623048387612511504', 'field', 'nokta-gold-kruzer', 'Gold Kruzer on alluvial gold', 'Le Gold Kruzer sur l\'or alluvionnaire', 'Gold Kruzer kwenye dhahabu ya mto', 'Zambia'],
        ['7646475059418205456', 'field', 'nokta-gold-finder-2000', 'Gold vein or ordinary rock? Testing a sample', 'Filon d\'or ou simple roche ? Test d\'un échantillon', 'Mshipa wa dhahabu au jiwe la kawaida?', 'Zambia'],
        ['7635678789757930768', 'field', 'nokta-gold-finder-2000', 'Gold Finder 2000, made for real field work', 'Le Gold Finder 2000, fait pour le vrai terrain', 'Gold Finder 2000, imetengenezwa kwa kazi halisi', 'Zambia'],
        ['7613770007134145809', 'field', '', 'Panning alone is slow. Find the spot first', 'La batée seule est lente. Trouvez d\'abord le bon endroit', 'Kuosha kwa sahani ni polepole. Tafuta mahali kwanza', 'Zambia'],
        ['7703891058639260949', 'learn', 'nokta-magnetar-9000', 'How a detector like the Magnetar 9000 works', 'Comment fonctionne un détecteur comme le Magnetar 9000', 'Jinsi kigunduzi kama Magnetar 9000 kinavyofanya kazi', 'Lusaka'],
        ['7669304689489448193', 'learn', 'nokta-magnetar-9000', 'Magnetar 9000 compared with the older 7000', 'Le Magnetar 9000 comparé à l\'ancien 7000', 'Magnetar 9000 ikilinganishwa na 7000', 'Lusaka'],
        ['7681224246630075649', 'learn', 'nokta-magnetar-9000', 'Why the Magnetar 9000 is the newest machine', 'Pourquoi le Magnetar 9000 est la machine la plus récente', 'Kwa nini Magnetar 9000 ni mashine mpya zaidi', 'Lusaka'],
        ['7626046028113890561', 'learn', 'nokta-gold-finder-2000', 'How the Gold Finder 2000 tells gold from trash', 'Comment le Gold Finder 2000 distingue l\'or des déchets', 'Jinsi Gold Finder 2000 inavyotambua dhahabu', 'Lusaka'],
        ['7635954399638048001', 'learn', 'nokta-gold-finder-2000', 'Not every detector is built for the field', 'Tous les détecteurs ne sont pas faits pour le terrain', 'Si kila kigunduzi kimetengenezwa kwa kazi ya shamba', 'Lusaka'],
        ['7621074541699239185', 'learn', 'nokta-gold-kruzer', 'Why 61 kHz finds small gold', 'Pourquoi 61 kHz trouve l\'or fin', 'Kwa nini 61 kHz hupata dhahabu ndogo', 'Lusaka'],
        ['7612711794112515329', 'learn', 'nokta-gold-kruzer', 'Gold Kruzer: a strong start for new prospectors', 'Gold Kruzer : un bon départ pour débuter', 'Gold Kruzer: mwanzo mzuri kwa wanaoanza', 'Lusaka'],
        ['7611526819308784912', 'learn,how_to_buy', 'nokta-gold-kruzer', 'Gold Finder 2000 or Gold Kruzer: which one for you?', 'Gold Finder 2000 ou Gold Kruzer : lequel choisir ?', 'Gold Finder 2000 au Gold Kruzer: kipi kwako?', 'Lusaka'],
        ['7697516965375577345', 'mumbwa', '', 'The Mumbwa gold challenge', 'Le défi de l\'or de Mumbwa', 'Shindano la dhahabu Mumbwa', 'Mumbwa, Zambia'],
        ['7699105145866571014', 'mumbwa', 'nokta-magnetar-9000', 'Contestant 1: five minutes to find gold', 'Candidat 1 : cinq minutes pour trouver de l\'or', 'Mshiriki 1: dakika tano kupata dhahabu', 'Mumbwa, Zambia'],
        ['7700540704514362644', 'mumbwa', 'nokta-magnetar-9000', 'Contestant 4 found gold in two minutes', 'Le candidat 4 a trouvé de l\'or en deux minutes', 'Mshiriki 4 alipata dhahabu kwa dakika mbili', 'Mumbwa, Zambia'],
        ['7699732649870363925', 'mumbwa,customers', '', 'What challenger 4 says after the test', 'Ce que dit le candidat 4 après le test', 'Anachosema mshiriki 4 baada ya jaribio', 'Mumbwa, Zambia'],
        ['7610328465220357393', 'customers', '', 'Visiting a customer site in Chibombo', 'Visite d\'un site client à Chibombo', 'Kutembelea eneo la mteja Chibombo', 'Chibombo, Zambia'],
        ['7667621547946904849', 'customers', 'nokta-magnetar-9000', 'Miners in Serenje with the Magnetar 9000', 'Des mineurs de Serenje avec le Magnetar 9000', 'Wachimbaji wa Serenje na Magnetar 9000', 'Serenje, Zambia'],
        ['7696069634390330644', 'home,machines', 'hpl-sluice-wash-plant', 'The HPL sluice box wash plant', 'La laverie à sluice HPL', 'Mashine ya sluice ya HPL', 'Lusaka', 'hplwashplant'],
        ['7703536612457729300', 'machines', 'hpl-sluice-wash-plant', 'The blue wash plant, ready to work', 'La laverie bleue, prête à travailler', 'Mashine ya bluu, tayari kufanya kazi', 'Lusaka', 'hplwashplant'],
        ['7700554899309825300', 'machines', 'hpl-sluice-wash-plant', 'Why miners lose gold, and how the sluice box stops it', 'Pourquoi les mineurs perdent de l\'or, et comment le sluice l\'évite', 'Kwa nini wachimbaji hupoteza dhahabu, na sluice inavyozuia', 'Lusaka', 'hplwashplant'],
        ['7703858224105000213', 'machines', 'hpl-sluice-wash-plant', 'Recover gold the easier way', 'Récupérer l\'or plus facilement', 'Pata dhahabu kwa urahisi zaidi', 'Lusaka', 'hplwashplant'],
        ['7698469413854481685', 'machines', 'hpl-sluice-wash-plant', 'Stop digging blind: let the machine do the work', 'Ne creusez plus à l\'aveugle : laissez la machine travailler', 'Acha kuchimba bila mwelekeo: acha mashine ifanye kazi', 'Lusaka', 'hplwashplant'],
        ['7697551094301347092', 'machines,showroom', 'hpl-sluice-wash-plant', 'The sluice box wash plant at our shop', 'La laverie à sluice dans notre magasin', 'Mashine ya sluice dukani kwetu', 'Lusaka', 'hplwashplant'],
        ['7702698658097073429', 'machines', 'hpl-sluice-wash-plant', 'A closer look at the wash plant', 'La laverie de plus près', 'Mashine kwa karibu', 'Lusaka', 'hplwashplant'],
        ['7702027939415706886', 'machines', 'hpl-sluice-wash-plant', 'Wash more gravel, keep the fine gold', 'Lavez plus de gravier, gardez l\'or fin', 'Osha kokoto nyingi, baki na dhahabu ndogo', 'Lusaka', 'hplwashplant'],
        ['7698763514797673780', 'machines', 'hpl-sluice-wash-plant', 'Water pump, pipes and gold mat included', 'Pompe, tuyaux et tapis à or inclus', 'Pampu, mabomba na mkeka vimejumuishwa', 'Lusaka', 'hplwashplant'],
        ['7697256988631223572', 'machines', 'hpl-sluice-wash-plant', 'Let the machine do the work', 'Laissez la machine travailler', 'Acha mashine ifanye kazi', 'Lusaka', 'hplwashplant'],
        ['7696576216236707078', 'machines', 'hpl-sluice-wash-plant', 'The wash plant in action', 'La laverie en action', 'Mashine ikifanya kazi', 'Lusaka', 'hplwashplant'],
        ['7676515764798541076', 'how_to_buy', 'nokta-magnetar-9000', 'Magnetar 9000 at K160,000', 'Le Magnetar 9000 à 160 000 kwacha', 'Magnetar 9000 kwa K160,000', 'Lusaka'],
        ['7613062446382714113', 'how_to_buy', '', 'Pay for your detector in instalments', 'Payez votre détecteur en plusieurs fois', 'Lipia kigunduzi chako kidogo kidogo', 'Lusaka'],
        ['7692002993784245505', 'how_to_buy,podcast', '', 'What to know before you buy any gold detector', 'Ce qu\'il faut savoir avant d\'acheter un détecteur', 'Unachopaswa kujua kabla ya kununua kigunduzi', 'HPL podcast'],
        ['7694604539764165940', 'how_to_buy,podcast', '', 'Don\'t trust blindly: how to choose the right machine', 'Ne faites pas confiance aveuglément : bien choisir sa machine', 'Usiamini bila kuangalia: chagua mashine sahihi', 'HPL podcast'],
        ['7704233246703226133', 'podcast', '', 'Compliance: running a legal gold mining business', 'La conformité : exploiter l\'or dans la légalité', 'Kufuata sheria katika biashara ya dhahabu', 'HPL podcast'],
        ['7684884597192150292', 'podcast', '', 'Understand before you invest', 'Comprendre avant d\'investir', 'Elewa kabla ya kuwekeza', 'HPL podcast'],
        ['7684592491261185297', 'showroom', 'nokta-magnetar-9000', 'The Magnetar 9000 is in our Lusaka shop', 'Le Magnetar 9000 est dans notre magasin de Lusaka', 'Magnetar 9000 iko dukani kwetu Lusaka', 'Lusaka'],
        ['7651673765327211793', 'showroom', 'nokta-magnetar-9000', 'Magnetar 9000 now in our stores', 'Le Magnetar 9000 en magasin', 'Magnetar 9000 sasa madukani kwetu', 'Lusaka'],
        ['7612645058508016916', 'showroom', '', 'Find us in Lusaka, Lumumba Road', 'Nous trouver à Lusaka, Lumumba Road', 'Tupate Lusaka, Lumumba Road', 'Lusaka'],
        ['7655003347354717441', 'showroom', '', 'Detectors, scanners and gold washers in one shop', 'Détecteurs, scanners et laveuses d\'or au même endroit', 'Vigunduzi, skana na mashine za kuosha dukani moja', 'Lusaka'],
    ];
}

function seed_settings(): array
{
    return [
        'co_address' => 'Lumumba Road, opposite Auto World, next to Lumumba bus station',
        'co_city' => 'Lusaka, Zambia',
        'co_phone' => '+260 966 499 575',
        'co_phone2' => '+260 975 040 243',
        'co_whatsapp' => '+260966499575',
        'co_whatsapp_wash' => '+260973898038',
        'consult_url' => 'https://hayatiprospectors.com/#iclosed-form',
        'co_email' => 'yasir@hayatiprospectors.com',
        'co_branches' => "Lusaka: Lumumba Road, opposite Auto World, next to Lumumba bus station\nKitwe: Nkana Mall, shop G29",
        'co_facebook' => 'https://www.facebook.com/profile.php?id=61594605834571',
        'co_website' => 'https://hayatiprospectors.com',
        'usd_rate' => '25',
        'tg_alerts' => '1', 'email_alerts' => '1',
        'chat_greeting' => seed_j([
            'en' => 'Hello! You are chatting with the HPL team in Lusaka. Ask us anything about a detector or machine, delivery to your country, or payment.',
            'fr' => 'Bonjour ! Vous parlez avec l\'équipe HPL à Lusaka. Posez-nous vos questions sur un détecteur, une machine, la livraison dans votre pays ou le paiement.',
            'sw' => 'Habari! Unaongea na timu ya HPL, Lusaka. Tuulize chochote kuhusu kigunduzi, mashine, usafirishaji au malipo.',
        ]),
    ];
}

/** Insert categories, products and videos (tables must be empty of them). */
function seed_catalogue(): void
{
    $catId = [];
    foreach (seed_categories() as $i => [$slug, $kind, $name, $blurb]) {
        $id = (int)val('SELECT id FROM shop_categories WHERE slug = ?', [$slug], 0);
        if ($id) {
            q('UPDATE shop_categories SET kind = ?, name = ?, blurb = ?, sort = ? WHERE id = ?', [$kind, seed_j($name), seed_j($blurb), $i * 10, $id]);
        } else {
            q('INSERT INTO shop_categories (slug, kind, name, blurb, sort) VALUES (?,?,?,?,?)', [$slug, $kind, seed_j($name), seed_j($blurb), $i * 10]);
            $id = (int)db()->lastInsertId();
        }
        $catId[$slug] = $id;
    }
    foreach (seed_products() as $i => $p) {
        [$slug, $cat, $brand, $name, $price, $stock, $warranty, $off, $feat, $img, $gal, $tag, $desc, $best, $specs, $box] = $p;
        $credit = $p[16] ?? '';
        $vals = [$catId[$cat], $brand, $name, seed_j($tag), seed_j($desc), seed_j($best), seed_j($specs), seed_j($box), $price ?: null, $stock, $warranty, $off, $feat, $i * 10, $img, seed_j($gal), $credit];
        $id = (int)val('SELECT id FROM shop_products WHERE slug = ?', [$slug], 0);
        if ($id) {
            q('UPDATE shop_products SET category_id=?, brand=?, name=?, tagline=?, description=?, best_for=?, specs=?, in_box=?, price_zmw=?, stock=?, warranty=?, official_url=?, featured=?, sort=?, image=?, gallery=?, photo_credit=?, published=1 WHERE id=?', array_merge($vals, [$id]));
        } else {
            q('INSERT INTO shop_products (category_id, brand, name, tagline, description, best_for, specs, in_box, price_zmw, stock, warranty, official_url, featured, sort, image, gallery, photo_credit, slug, published) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)', array_merge($vals, [$slug]));
        }
    }
    $pid = function (string $slug) { return (int)val('SELECT id FROM shop_products WHERE slug = ?', [$slug], 0) ?: null; };
    foreach (seed_videos() as $i => $row) {
        [$id, $sec, $slug, $en, $fr, $sw, $place] = $row;
        $tt = 'https://www.tiktok.com/@' . ($row[7] ?? 'hpldetectors.zambia') . '/video/';
        $file = is_file(STORE_UPLOADS . "/videos/tt/$id.mp4") ? "videos/tt/$id.mp4" : null;
        $poster = is_file(STORE_UPLOADS . "/posters/tt-$id.jpg") ? "posters/tt-$id.jpg" : null;
        $old = row('SELECT id, poster FROM shop_videos WHERE embed_url = ?', [$tt . $id]);
        $vals = [seed_j(['en' => $en, 'fr' => $fr, 'sw' => $sw]), 'portrait', $sec, $slug ? $pid($slug) : null, $place, $i * 10];
        if ($old) {
            q('UPDATE shop_videos SET title=?, orientation=?, sections=?, product_id=?, place=?, sort=?, published=1, file=COALESCE(?, file), poster=COALESCE(poster, ?) WHERE id=?', array_merge($vals, [$file, $poster, $old['id']]));
        } else {
            q('INSERT INTO shop_videos (title, orientation, sections, product_id, place, sort, embed_url, file, poster) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($vals, [$tt . $id, $file, $poster]));
        }
    }
}

/** First install. */
function store_seed(): void
{
    seed_catalogue();
    foreach (seed_settings() as $k => $v) q('INSERT IGNORE INTO shop_settings (k, v) VALUES (?, ?)', [$k, $v]);
}

/** Live site, once: replace the first catalogue with the real one. */
function store_catalogue_v2(): void
{
    $keep = array_map(fn($p) => $p[0], seed_products());
    $in = implode(',', array_fill(0, count($keep), '?'));
    q("UPDATE shop_products SET published = 0, featured = 0 WHERE slug NOT IN ($in)", $keep);
    $tt = 'https://www.tiktok.com/@hpldetectors.zambia/video/';
    $vids = array_map(fn($v) => $tt . $v[0], seed_videos());
    $in = implode(',', array_fill(0, count($vids), '?'));
    q("UPDATE shop_videos SET published = 0 WHERE embed_url LIKE '%tiktok.com/@hpldetectors.zambia/%' AND embed_url NOT IN ($in)", $vids);
    seed_catalogue();
    // Remove the first catalogue's categories (their hidden products keep working for old orders).
    $old = "'wash-plants','jaw-crushers','hammer-mills','processing','pinpointers'";
    q("UPDATE shop_products p JOIN shop_categories c ON c.id = p.category_id SET p.category_id = NULL WHERE c.slug IN ($old) AND p.published = 0");
    q("DELETE FROM shop_categories WHERE slug IN ($old) AND NOT EXISTS (SELECT 1 FROM shop_products p WHERE p.category_id = shop_categories.id)");
    // Order history from before the split pointed at another site's user ids.
    q('UPDATE shop_order_events SET by_admin = NULL');
    foreach (seed_settings() as $k => $v) {
        if (in_array($k, ['co_address', 'co_branches', 'co_facebook', 'co_email', 'co_website', 'usd_rate'], true)) {
            q('INSERT INTO shop_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        } else {
            q('INSERT IGNORE INTO shop_settings (k, v) VALUES (?, ?)', [$k, $v]);
        }
    }
    q("DELETE FROM shop_settings WHERE k IN ('co_tiktok2', 'co_nokta_listing')");
}

/** Live site, once: the new blue sluice box wash plant replaces the old gold washer. */
function store_catalogue_v3(): void
{
    q("UPDATE shop_products SET published = 0, featured = 0 WHERE slug IN ('hpl-gold-washer', 'sluice-box')");
    q("UPDATE shop_videos SET published = 0 WHERE embed_url REGEXP '/video/(7621560953779309841|7631098836873710849|7628286625646234897|7616066815302618385)$'");
    seed_catalogue();
    foreach (['co_whatsapp_wash' => '+260973898038', 'consult_url' => 'https://hayatiprospectors.com/#iclosed-form'] as $k => $v) {
        q('INSERT IGNORE INTO shop_settings (k, v) VALUES (?, ?)', [$k, $v]);
    }
}

/** Live site, once: wash plant price and the accessories (pinpointer, scale, gold pan, crucible). */
function store_catalogue_v4(): void
{
    seed_catalogue(); // upserts everything in the seed, including the new accessories
}
