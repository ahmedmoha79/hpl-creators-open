<?php
/**
 * Buyer guides: plain answers to what miners ask us before they buy. Each guide has a title,
 * a one-line summary and paragraphs, in English, French and Swahili, plus the products it is about.
 * Facts only; no promises of depth or finds.
 */
function guides(): array
{
    return [
        'how-deep-can-a-gold-detector-find-gold' => [
            'products' => ['nokta-magnetar-9000', 'nokta-gold-finder-2000', 'nokta-gold-kruzer'],
            'en' => ['How deep can a gold detector find gold?', 'There is no single depth. It depends on three things: the size of the gold, the ground and the detector.', [
                'Anyone who gives you one number for every situation is not telling you the truth. A detector finds a large nugget much deeper than a small flake, and the same detector goes deeper in clean sand than in red, iron-rich soil.',
                'Size of the gold: the bigger the piece, the deeper it can be found. Very small gold is only found close to the surface, and a high-frequency detector such as the Gold Kruzer or Gold Finder 2000 (61 kHz) is best for it.',
                'The ground: much of Zambia, Congo and West Africa has mineralised soil full of iron. It confuses ordinary detectors and makes them lose depth. A pulse induction detector such as the Magnetar 9000 is built to ignore most of this, so it keeps more of its depth in hard ground.',
                'The detector and the coil: a larger coil covers more ground and reaches deeper on bigger gold; a smaller coil is better for small gold and tight places. Our detectors come with two coils for this reason.',
                'Tell us the size of gold that is found in your area and what your ground looks like, and we will tell you honestly which detector fits. If you are not sure, our team can advise you before you spend any money.',
            ]],
            'fr' => ['À quelle profondeur un détecteur trouve-t-il l\'or ?', 'Il n\'y a pas une seule profondeur. Elle dépend de trois choses : la taille de l\'or, le sol et le détecteur.', [
                'Celui qui vous donne un seul chiffre pour toutes les situations ne vous dit pas la vérité. Un détecteur trouve une grosse pépite bien plus profondément qu\'une petite paillette, et le même détecteur va plus loin dans du sable propre que dans un sol rouge riche en fer.',
                'La taille de l\'or : plus la pièce est grosse, plus elle peut être trouvée profondément. L\'or très fin ne se trouve que près de la surface, et un détecteur à haute fréquence comme le Gold Kruzer ou le Gold Finder 2000 (61 kHz) est le meilleur pour cela.',
                'Le sol : une grande partie de la Zambie, du Congo et de l\'Afrique de l\'Ouest a un sol minéralisé plein de fer. Il trouble les détecteurs ordinaires et leur fait perdre de la profondeur. Un détecteur à induction pulsée comme le Magnetar 9000 est conçu pour ignorer la plupart de ces effets et garde plus de profondeur en sol difficile.',
                'Le détecteur et le disque : un grand disque couvre plus de terrain et va plus loin sur l\'or plus gros ; un petit disque est meilleur pour l\'or fin et les endroits étroits. C\'est pourquoi nos détecteurs sont livrés avec deux disques.',
                'Dites-nous la taille de l\'or trouvé dans votre zone et l\'aspect de votre sol : nous vous dirons honnêtement quel détecteur convient. Si vous hésitez, notre équipe peut vous conseiller avant que vous dépensiez quoi que ce soit.',
            ]],
            'sw' => ['Kigunduzi kinapata dhahabu kwa kina gani?', 'Hakuna kina kimoja. Kinategemea mambo matatu: ukubwa wa dhahabu, udongo na kigunduzi.', [
                'Mtu anayekupa namba moja kwa kila hali hakuambii ukweli. Kigunduzi kinapata kipande kikubwa chini zaidi kuliko kipande kidogo, na kigunduzi kile kile kinaenda chini zaidi kwenye mchanga safi kuliko kwenye udongo mwekundu wenye chuma.',
                'Ukubwa wa dhahabu: kipande kikiwa kikubwa, kinapatikana chini zaidi. Dhahabu ndogo sana inapatikana karibu na juu tu, na kigunduzi cha masafa ya juu kama Gold Kruzer au Gold Finder 2000 (61 kHz) ni bora kwa hiyo.',
                'Udongo: sehemu kubwa ya Zambia, Kongo na Afrika Magharibi ina udongo wenye madini ya chuma. Unachanganya vigunduzi vya kawaida na kuvipunguzia kina. Kigunduzi cha pulse induction kama Magnetar 9000 kimetengenezwa kupuuza mengi ya haya, hivyo kinabaki na kina zaidi kwenye udongo mgumu.',
                'Kigunduzi na koili: koili kubwa inafunika eneo kubwa na inaenda chini zaidi kwa dhahabu kubwa; koili ndogo ni bora kwa dhahabu ndogo na sehemu nyembamba. Ndiyo maana vigunduzi vyetu vinakuja na koili mbili.',
                'Tuambie ukubwa wa dhahabu inayopatikana eneo lako na udongo wako unavyoonekana, tutakuambia kwa ukweli kigunduzi kipi kinafaa. Kama huna uhakika, timu yetu inaweza kukushauri kabla hujatumia pesa.',
            ]],
        ],
        'pulse-induction-or-vlf' => [
            'products' => ['nokta-magnetar-9000', 'nokta-gold-finder-2000', 'nokta-gold-kruzer'],
            'en' => ['Pulse induction or VLF: which detector for your ground?', 'The two technologies behind gold detectors, explained simply, and when each one is the right choice.', [
                'VLF (very low frequency) detectors send a steady signal into the ground. Gold detectors of this type run at a high frequency, like 61 kHz on the Gold Finder 2000 and Gold Kruzer, which makes them very sensitive to small gold near the surface. They are lighter and cost less.',
                'Pulse induction (PI) detectors send short, strong pulses and listen for the echo. They are much less disturbed by mineralised, iron-rich ground and hot rocks, which is where VLF detectors struggle. The Magnetar 9000 is Nokta\'s pulse induction gold detector.',
                'Choose VLF (Gold Finder 2000 or Gold Kruzer) if: you are starting out, you look for small gold near the surface, you work alluvial ground and rivers, or your budget is limited. The Gold Kruzer is fully waterproof, so it is the one for river work.',
                'Choose PI (Magnetar 9000) if: you work hard, red, mineralised ground, you are after bigger and deeper gold, or other detectors in your area keep giving false signals.',
                'Many serious miners own both: a PI detector for depth and a VLF detector for small gold and for checking the material from the pit.',
            ]],
            'fr' => ['Induction pulsée ou VLF : quel détecteur pour votre sol ?', 'Les deux technologies des détecteurs d\'or, expliquées simplement, et quand choisir chacune.', [
                'Les détecteurs VLF (très basse fréquence) envoient un signal continu dans le sol. Les détecteurs d\'or de ce type fonctionnent à haute fréquence, comme 61 kHz pour le Gold Finder 2000 et le Gold Kruzer, ce qui les rend très sensibles à l\'or fin près de la surface. Ils sont plus légers et moins chers.',
                'Les détecteurs à induction pulsée (PI) envoient des impulsions courtes et fortes et écoutent l\'écho. Ils sont beaucoup moins gênés par les sols minéralisés riches en fer et les « pierres chaudes », là où les VLF ont du mal. Le Magnetar 9000 est le détecteur d\'or à induction pulsée de Nokta.',
                'Choisissez un VLF (Gold Finder 2000 ou Gold Kruzer) si : vous débutez, vous cherchez l\'or fin près de la surface, vous travaillez en alluvion et en rivière, ou votre budget est limité. Le Gold Kruzer est entièrement étanche : c\'est celui pour la rivière.',
                'Choisissez un PI (Magnetar 9000) si : vous travaillez un sol dur, rouge et minéralisé, vous cherchez de l\'or plus gros et plus profond, ou les autres détecteurs de votre zone donnent sans cesse de faux signaux.',
                'Beaucoup de mineurs sérieux ont les deux : un PI pour la profondeur et un VLF pour l\'or fin et pour contrôler la terre sortie du puits.',
            ]],
            'sw' => ['Pulse induction au VLF: kigunduzi kipi kwa udongo wako?', 'Teknolojia mbili za vigunduzi vya dhahabu, kwa maneno rahisi, na wakati kila moja inafaa.', [
                'Vigunduzi vya VLF vinatuma ishara isiyokatika ardhini. Vigunduzi vya dhahabu vya aina hii vinatumia masafa ya juu, kama 61 kHz kwa Gold Finder 2000 na Gold Kruzer, hivyo vinahisi sana dhahabu ndogo karibu na juu. Ni vyepesi na bei nafuu.',
                'Vigunduzi vya pulse induction (PI) vinatuma mipigo mifupi yenye nguvu na kusikiliza mwangwi. Havisumbuliwi sana na udongo wenye madini ya chuma na mawe ya joto, pale VLF zinapopata shida. Magnetar 9000 ni kigunduzi cha dhahabu cha pulse induction cha Nokta.',
                'Chagua VLF (Gold Finder 2000 au Gold Kruzer) kama: unaanza, unatafuta dhahabu ndogo karibu na juu, unafanya kazi mtoni, au bajeti yako ni ndogo. Gold Kruzer haiingii maji kabisa, hivyo ndiyo ya mtoni.',
                'Chagua PI (Magnetar 9000) kama: unafanya kazi kwenye udongo mgumu mwekundu wenye madini, unatafuta dhahabu kubwa na iliyo chini, au vigunduzi vingine eneo lako vinatoa sauti za uongo.',
                'Wachimbaji wengi makini wana vyote viwili: PI kwa kina na VLF kwa dhahabu ndogo na kukagua udongo uliotoka shimoni.',
            ]],
        ],
        'alluvial-or-reef-gold' => [
            'products' => ['nokta-gold-kruzer', 'nokta-magnetar-9000', 'hpl-sluice-wash-plant'],
            'en' => ['Alluvial gold or reef gold: what equipment do you need?', 'Where your gold sits decides the right detector and whether you need a wash plant.', [
                'Alluvial gold has been carried by water. It is found in rivers, streams, old river beds and the gravel and soil around them, often as small pieces and flakes.',
                'For alluvial work, a waterproof high-frequency detector like the Gold Kruzer helps you find the gold-bearing spots, and a sluice box wash plant lets you wash large amounts of gravel and keep the fine gold that a detector cannot see.',
                'Reef or hard-rock gold sits in quartz veins and the ground around them. Pieces can be deeper and the ground is often hard and mineralised, which is where a pulse induction detector like the Magnetar 9000 is the stronger choice.',
                'Many sites have both: nuggets in the soil above a reef, and fine gold in the stream below it. Tell us what is found around your site and we will help you put together the right equipment.',
            ]],
            'fr' => ['Or alluvionnaire ou or filonien : de quel équipement avez-vous besoin ?', 'L\'endroit où se trouve votre or décide du bon détecteur et du besoin d\'une laverie.', [
                'L\'or alluvionnaire a été transporté par l\'eau. On le trouve dans les rivières, les ruisseaux, les anciens lits de rivière et le gravier et la terre autour, souvent en petits morceaux et en paillettes.',
                'Pour l\'alluvion, un détecteur étanche à haute fréquence comme le Gold Kruzer aide à trouver les zones riches, et une laverie à sluice permet de laver de grandes quantités de gravier et de garder l\'or fin qu\'un détecteur ne voit pas.',
                'L\'or filonien se trouve dans les filons de quartz et le sol autour. Les pièces peuvent être plus profondes et le sol est souvent dur et minéralisé : c\'est là qu\'un détecteur à induction pulsée comme le Magnetar 9000 est le meilleur choix.',
                'Beaucoup de sites ont les deux : des pépites dans le sol au-dessus d\'un filon et de l\'or fin dans le ruisseau en dessous. Dites-nous ce qu\'on trouve autour de votre site et nous vous aiderons à choisir le bon équipement.',
            ]],
            'sw' => ['Dhahabu ya mto au dhahabu ya mwamba: unahitaji vifaa gani?', 'Mahali dhahabu yako ilipo kunaamua kigunduzi sahihi na kama unahitaji mashine ya kuosha.', [
                'Dhahabu ya mto imebebwa na maji. Inapatikana mitoni, vijitoni, mito ya zamani na kokoto na udongo unaozunguka, mara nyingi kama vipande vidogo.',
                'Kwa dhahabu ya mto, kigunduzi kisichoingia maji cha masafa ya juu kama Gold Kruzer kinakusaidia kupata sehemu zenye dhahabu, na mashine ya sluice inakuwezesha kuosha kokoto nyingi na kubaki na dhahabu ndogo ambayo kigunduzi hakiioni.',
                'Dhahabu ya mwamba iko kwenye mishipa ya quartz na udongo unaoizunguka. Vipande vinaweza kuwa chini zaidi na udongo mara nyingi ni mgumu wenye madini, hapo kigunduzi cha pulse induction kama Magnetar 9000 ndicho bora.',
                'Maeneo mengi yana vyote viwili: vipande kwenye udongo juu ya mwamba, na dhahabu ndogo kwenye kijito chini yake. Tuambie kinachopatikana eneo lako tukusaidie kuchagua vifaa sahihi.',
            ]],
        ],
        'what-is-sambaza' => [
            'products' => ['nokta-magnetar-9000', 'nokta-gold-finder-2000'],
            'en' => ['What is sambaza?', 'A simple way miners use a detector to check the soil that comes out of a pit, so no nugget goes back on the waste heap.', [
                'In sambaza, soil from the pit is spread out in a thin layer on the ground. The miner then sweeps it with a gold detector. A nugget that was hidden in a heap is now close to the coil, so even a small piece gives a clear signal.',
                'It works well with old pits and waste heaps that other miners already dug and left. Gold is often thrown away with the soil because nobody checked it.',
                'Spread the soil thin and keep the coil close and level. Go slowly and check every signal with your hand or a pinpointer before you throw the soil away.',
                'Watch our sambaza videos from the Mumbwa challenge to see it done in real time.',
            ]],
            'fr' => ['Qu\'est-ce que le sambaza ?', 'Une méthode simple des mineurs pour contrôler au détecteur la terre sortie d\'un puits, pour qu\'aucune pépite ne reparte sur le tas de déchets.', [
                'Avec le sambaza, la terre du puits est étalée en couche fine sur le sol. Le mineur la balaie ensuite avec un détecteur d\'or. Une pépite cachée dans un tas se retrouve près du disque : même une petite pièce donne un signal clair.',
                'Cela marche bien avec les anciens puits et les tas de déchets que d\'autres ont creusés et abandonnés. L\'or est souvent jeté avec la terre parce que personne ne l\'a contrôlée.',
                'Étalez la terre finement et gardez le disque près du sol et à plat. Allez lentement et vérifiez chaque signal à la main ou au pinpointer avant de jeter la terre.',
                'Regardez nos vidéos de sambaza du défi de Mumbwa pour le voir en temps réel.',
            ]],
            'sw' => ['Sambaza ni nini?', 'Njia rahisi ambayo wachimbaji hutumia kigunduzi kukagua udongo unaotoka shimoni, ili kipande chochote kisirudi kwenye lundo la taka.', [
                'Kwenye sambaza, udongo wa shimo unatandazwa kwa tabaka jembamba ardhini. Kisha mchimbaji anaupitisha kigunduzi. Kipande kilichojificha kwenye lundo sasa kiko karibu na koili, hivyo hata kipande kidogo kinatoa ishara wazi.',
                'Inafanya kazi vizuri kwenye mashimo ya zamani na malundo ambayo wengine walichimba na kuacha. Dhahabu mara nyingi hutupwa na udongo kwa sababu hakuna aliyeukagua.',
                'Tandaza udongo kwa jembamba na weka koili karibu na sawasawa. Nenda polepole na kagua kila ishara kwa mkono au pinpointer kabla ya kutupa udongo.',
                'Tazama video zetu za sambaza kutoka shindano la Mumbwa uone ikifanyika.',
            ]],
        ],
        'long-range-gold-scanners' => [
            'products' => ['nokta-magnetar-9000', 'nokta-gold-finder-2000', 'nokta-gold-kruzer'],
            'en' => ['"Long-range" gold scanners: why we don\'t sell them', 'Devices that promise to find gold at 20, 50 or 100 metres, or from far away, do not work. Here is how to protect your money.', [
                'Some sellers offer "long-range locators", "3D ground scanners" or devices with antennas that point you towards gold from far away. Scientific tests and many miners\' losses show they do not detect gold.',
                'A real gold detector, like every Nokta we sell, works by sending an electromagnetic field into the ground under its coil and reading what comes back. It finds gold under the coil, not across a field.',
                'Warning signs: a promise of a depth like 30 metres or more, a promise to "show the exact amount of gold", a price far above real detectors, or a seller who will not show the machine finding gold in front of you.',
                'Before you buy any detector, from us or anyone else, ask to see it find a real target in the ground. We are happy to show you in our shop, in the field or on a live video call.',
            ]],
            'fr' => ['Scanners d\'or « longue portée » : pourquoi nous n\'en vendons pas', 'Les appareils qui promettent de trouver l\'or à 20, 50 ou 100 mètres, ou de loin, ne fonctionnent pas. Voici comment protéger votre argent.', [
                'Certains vendeurs proposent des « localisateurs longue portée », des « scanners 3D » ou des appareils à antenne qui vous orientent vers l\'or de loin. Les tests scientifiques et les pertes de nombreux mineurs montrent qu\'ils ne détectent pas l\'or.',
                'Un vrai détecteur d\'or, comme chaque Nokta que nous vendons, envoie un champ électromagnétique dans le sol sous son disque et lit ce qui revient. Il trouve l\'or sous le disque, pas à travers un champ.',
                'Signaux d\'alerte : une promesse de profondeur de 30 mètres ou plus, la promesse de « montrer la quantité exacte d\'or », un prix bien plus élevé que les vrais détecteurs, ou un vendeur qui refuse de montrer la machine trouver de l\'or devant vous.',
                'Avant d\'acheter un détecteur, chez nous ou ailleurs, demandez à le voir trouver une vraie cible dans le sol. Nous vous le montrons volontiers au magasin, sur le terrain ou en appel vidéo.',
            ]],
            'sw' => ['Skana za dhahabu za "long range": kwa nini hatuziuzi', 'Vifaa vinavyoahidi kupata dhahabu mita 20, 50 au 100, au kutoka mbali, havifanyi kazi. Hivi ndivyo unavyolinda pesa zako.', [
                'Wauzaji wengine wanatoa "long range locators", "3D scanners" au vifaa vyenye antena vinavyokuonyesha dhahabu kutoka mbali. Majaribio ya kisayansi na hasara za wachimbaji wengi zinaonyesha havigundui dhahabu.',
                'Kigunduzi halisi cha dhahabu, kama kila Nokta tunayouza, kinatuma nguvu za sumaku ardhini chini ya koili yake na kusoma kinachorudi. Kinapata dhahabu iliyo chini ya koili, si upande wa pili wa shamba.',
                'Dalili za hatari: ahadi ya kina cha mita 30 au zaidi, ahadi ya "kuonyesha kiasi kamili cha dhahabu", bei kubwa kuliko vigunduzi halisi, au muuzaji anayekataa kuonyesha mashine ikipata dhahabu mbele yako.',
                'Kabla ya kununua kigunduzi, kwetu au kwa mtu mwingine, omba kukiona kikipata kitu halisi ardhini. Tunafurahi kukuonyesha dukani, shambani au kwa simu ya video.',
            ]],
        ],
        'first-day-with-a-gold-detector' => [
            'products' => ['nokta-gold-finder-2000', 'nokta-gold-kruzer', 'nokta-magnetar-9000'],
            'en' => ['Your first day with a gold detector', 'Six habits that make the difference between finding gold and walking past it.', [
                'Ground balance first. Let the detector adjust to your soil before you search, and again when the ground changes. Your detector does this automatically or with one button; we show you how during training.',
                'Swing low and slow. Keep the coil flat and close to the ground, and move it slowly from side to side. Most missed gold is missed because the coil was too high or too fast.',
                'Overlap your swings. Step forward a little at a time so every part of the ground passes under the coil.',
                'Dig the signals you are not sure of. Small gold can sound weak. Check the hole and the soil you take out until you know what each sound means on your ground.',
                'Pinpoint before you dig. Use the pinpoint button to find the exact spot, so you dig a small hole instead of a big one.',
                'Fill your holes and respect the land owner and the law. Make sure you have permission and the right licence for where you search.',
            ]],
            'fr' => ['Votre premier jour avec un détecteur d\'or', 'Six habitudes qui font la différence entre trouver l\'or et passer à côté.', [
                'Équilibrez d\'abord le sol. Laissez le détecteur s\'adapter à votre terrain avant de chercher, et à nouveau quand le sol change. Il le fait seul ou avec un bouton ; nous vous montrons comment pendant la formation.',
                'Balayez bas et lentement. Gardez le disque à plat et près du sol, et déplacez-le lentement de gauche à droite. La plupart de l\'or manqué l\'est parce que le disque était trop haut ou trop rapide.',
                'Chevauchez vos passages. Avancez un peu à la fois pour que tout le sol passe sous le disque.',
                'Creusez les signaux dont vous n\'êtes pas sûr. L\'or fin peut donner un son faible. Contrôlez le trou et la terre sortie jusqu\'à connaître chaque son sur votre terrain.',
                'Localisez avant de creuser. Utilisez le bouton pinpoint pour trouver l\'endroit exact et creusez un petit trou au lieu d\'un grand.',
                'Rebouchez vos trous et respectez le propriétaire et la loi. Assurez-vous d\'avoir l\'autorisation et le bon permis pour l\'endroit où vous cherchez.',
            ]],
            'sw' => ['Siku yako ya kwanza na kigunduzi cha dhahabu', 'Tabia sita zinazoleta tofauti kati ya kupata dhahabu na kuipita.', [
                'Pima udongo kwanza. Acha kigunduzi kijipime na udongo wako kabla ya kutafuta, na tena udongo ukibadilika. Kinafanya hivi chenyewe au kwa kitufe kimoja; tunakuonyesha wakati wa mafunzo.',
                'Pitisha chini na polepole. Weka koili sawasawa na karibu na ardhi, na isogeze polepole kushoto na kulia. Dhahabu nyingi inapitwa kwa sababu koili ilikuwa juu sana au haraka sana.',
                'Pishanisha mapito yako. Songa mbele kidogo kidogo ili kila sehemu ya ardhi ipite chini ya koili.',
                'Chimba ishara usizo na uhakika nazo. Dhahabu ndogo inaweza kutoa sauti dhaifu. Kagua shimo na udongo unaotoa hadi ujue kila sauti inamaanisha nini kwenye udongo wako.',
                'Tafuta mahali hasa kabla ya kuchimba. Tumia kitufe cha pinpoint kupata mahali hasa, uchimbe shimo dogo badala ya kubwa.',
                'Fukia mashimo yako na heshimu mwenye ardhi na sheria. Hakikisha una ruhusa na leseni sahihi kwa mahali unapotafuta.',
            ]],
        ],
    ];
}

function guide(string $slug): ?array { return guides()[$slug] ?? null; }
function guide_text(array $g, ?string $l = null): array { $l = $l ?? lang(); return $g[$l] ?? $g['en']; }
