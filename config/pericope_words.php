<?php

/*
|--------------------------------------------------------------------------
| PERICOPE SHORT-LINK WORD POOLS                          short-link r1
|--------------------------------------------------------------------------
| The gfycat formula with a concordance: Adjective + Adjective + Noun,
| TitleCased and concatenated — megabible.net/SweetHoneyedEmber. Minting
| picks two DISTINCT adjectives and one noun at random (App\Support\
| PericopeCode) and retries on the rare collision.
|
| Namespace: 165 × 164 × 190 = 5,141,400 combinations. At a few thousand
| mints the space is so sparse that guessing a live code by enumeration
| is hopeless — which is itself a privacy property (no gallery, no index,
| no crawlable surface; a code is only known to the people it was sent to).
|
| CURATION RULES (hold these when adding words):
|   · Adjectives are POSITIVE or NEUTRAL only. No False/Wicked/Fallen/
|     Proud — on this site, no random combination may ever read as
|     blasphemy or an insult. (Also dropped for double meanings:
|     Hammered, Forged.)
|   · Nouns are the animals, plants, objects, and liturgy of scripture —
|     Leviticus 11 and the KJV's bestiary are the gift that keeps giving
|     (Coney, Hoopoe, Roebuck, Unicorn). Skipped the villains and the
|     sorrows: no Serpent, Dragon, Moth, Worm, Frog (plague).
|   · Every word TitleCase, letters only, one word. Shortest legal code
|     is 10 chars (the root route constraint's floor); longest ~33.
|   · Words may be ADDED freely (old codes never depend on the pools —
|     the DB row is the truth). Never REMOVE a word; it costs nothing
|     to keep and removal buys nothing.
*/

return [

    'adjectives' => [
        'Abiding', 'Abundant', 'Amber', 'Anchored', 'Ancient', 'Anointed',
        'Azure', 'Beloved', 'Blazing', 'Blessed', 'Blooming', 'Bountiful',
        'Bright', 'Brimming', 'Bronze', 'Burning', 'Burnished', 'Called',
        'Carved', 'Cherished', 'Cherubic', 'Chosen', 'Cleansed', 'Consecrated',
        'Crimson', 'Crowned', 'Dawning', 'Devout', 'Dewy', 'Discerning',
        'Earnest', 'Edenic', 'Emerald', 'Enduring', 'Engraved', 'Eternal',
        'Everlasting', 'Exalted', 'Fair', 'Faithful', 'Fervent', 'Festal',
        'Flourishing', 'Flowing', 'Fragrant', 'Fruitful', 'Galilean', 'Gathered',
        'Generous', 'Gentle', 'Gilded', 'Girded', 'Glad', 'Gleaming',
        'Glorious', 'Golden', 'Gracious', 'Grateful', 'Grounded', 'Guarded',
        'Hallowed', 'Heavenly', 'Hewn', 'Hidden', 'Honest', 'Honeyed',
        'Honored', 'Hopeful', 'Humble', 'Inscribed', 'Ivory', 'Joyful',
        'Jubilant', 'Kind', 'Kindled', 'Lamplit', 'Learned', 'Levitical',
        'Living', 'Lovely', 'Loving', 'Lowly', 'Luminous', 'Marvelous',
        'Meek', 'Merciful', 'Mighty', 'Misty', 'Moonlit', 'Morning',
        'Noble', 'Patient', 'Peaceful', 'Pearly', 'Pilgrim', 'Planted',
        'Pleasant', 'Polished', 'Prayerful', 'Prudent', 'Pure', 'Purified',
        'Purple', 'Quiet', 'Radiant', 'Redeemed', 'Refined', 'Regal',
        'Renewed', 'Restored', 'Resounding', 'Reverent', 'Righteous', 'Ringing',
        'Risen', 'Robed', 'Rooted', 'Royal', 'Rushing', 'Sacred',
        'Sapphire', 'Sealed', 'Serene', 'Sheltered', 'Shining', 'Silver',
        'Singing', 'Snowy', 'Soaring', 'Solemn', 'Sovereign', 'Sown',
        'Spotless', 'Springing', 'Starlit', 'Stately', 'Steadfast', 'Still',
        'Sunlit', 'Sweet', 'Swift', 'Tender', 'Thankful', 'Thundering',
        'Tranquil', 'Triumphant', 'True', 'Trusty', 'Unleavened', 'Upright',
        'Valiant', 'Veiled', 'Verdant', 'Victorious', 'Vigilant', 'Wandering',
        'Washed', 'Watchful', 'Whispering', 'Winged', 'Wise', 'Woven',
        'Wondrous', 'Worthy', 'Zealous',    ],

    'nouns' => [
        'Acacia', 'Almond', 'Altar', 'Anchor', 'Ant', 'Antelope',
        'Ape', 'Ark', 'Badger', 'Balm', 'Barley', 'Basin',
        'Bear', 'Bee', 'Behemoth', 'Bell', 'Bittern', 'Brook',
        'Bullock', 'Bulrush', 'Camel', 'Canticle', 'Cedar', 'Censer',
        'Chameleon', 'Chamois', 'Cinnamon', 'Cloud', 'Colt', 'Coney',
        'Cormorant', 'Cornerstone', 'Covenant', 'Crane', 'Cricket', 'Crown',
        'Cruse', 'Cymbal', 'Cypress', 'Daystar', 'Dew', 'Diadem',
        'Doe', 'Donkey', 'Dove', 'Dromedary', 'Eagle', 'Elephant',
        'Ember', 'Ephah', 'Epistle', 'Ewe', 'Falcon', 'Fawn',
        'Field', 'Fig', 'Flame', 'Flask', 'Flute', 'Fountain',
        'Garden', 'Gate', 'Gazelle', 'Gecko', 'Goat', 'Gospel',
        'Grace', 'Grasshopper', 'Harp', 'Hart', 'Hawk', 'Heifer',
        'Hen', 'Herald', 'Heron', 'Hind', 'Honey', 'Honeybee',
        'Honeycomb', 'Hoopoe', 'Horn', 'Horse', 'Hosanna', 'Hymn',
        'Hyssop', 'Ibex', 'Inkhorn', 'Jar', 'Jubilee', 'Lamb',
        'Lamp', 'Lampstand', 'Lentil', 'Leopard', 'Leviathan', 'Lily',
        'Lion', 'Lioness', 'Lizard', 'Locust', 'Lyre', 'Manna',
        'Mantle', 'Menorah', 'Millet', 'Mite', 'Mule', 'Myrrh',
        'Myrtle', 'Net', 'Omer', 'Orchard', 'Osprey', 'Ostrich',
        'Owl', 'Ox', 'Palm', 'Parable', 'Parchment', 'Partridge',
        'Passover', 'Pasture', 'Peacock', 'Pelican', 'Pentecost', 'Pigeon',
        'Pillar', 'Pitcher', 'Pomegranate', 'Prophet', 'Proverb', 'Psalm',
        'Psalmist', 'Psaltery', 'Quail', 'Rainbow', 'Ram', 'Raven',
        'Reed', 'River', 'Rock', 'Rod', 'Roebuck', 'Rose',
        'Sabbath', 'Saffron', 'Sandal', 'Scepter', 'Scribe', 'Scroll',
        'Seal', 'Selah', 'Sheaf', 'Sheepfold', 'Shekel', 'Shekinah',
        'Shepherd', 'Shield', 'Shofar', 'Signet', 'Sling', 'Sparrow',
        'Spikenard', 'Spring', 'Staff', 'Star', 'Stone', 'Stork',
        'Swallow', 'Swan', 'Tablet', 'Talent', 'Timbrel', 'Torch',
        'Tortoise', 'Trumpet', 'Turtledove', 'Unicorn', 'Vessel', 'Vine',
        'Vineyard', 'Watchman', 'Well', 'Whale', 'Wheat', 'Willow',
        'Wind', 'Winepress', 'Wolf', 'Yoke',    ],

];
