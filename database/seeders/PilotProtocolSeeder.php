<?php

namespace Database\Seeders;

use App\Enums\ProtocolPhase;
use App\Enums\TaskKind;
use App\Models\ProtocolVersion;
use App\Services\Assistance\DefaultPrompts;
use Illuminate\Database\Seeder;

/**
 * A complete, runnable pilot protocol. The target concept used here —
 * recursion over nested data structures — is a working candidate that meets
 * the selection criteria (new to most basic-level learners, teachable in
 * 20–30 minutes, supports near and far transfer, not solvable by copying a
 * memorised template). The final concept is decided after the pilot.
 */
class PilotProtocolSeeder extends Seeder
{
    public function run(): void
    {
        if (ProtocolVersion::where('code', 'PILOT-v1')->exists()) {
            return;
        }

        $protocol = ProtocolVersion::create([
            'code' => 'PILOT-v1',
            'name' => 'Pilot uygulama — sürüm 1',
            'phase' => ProtocolPhase::Pilot,
            'description' => 'Koşul başına 2–3 katılımcı ile protokolün uygulanabilirlik doğrulaması. Bu sürümün verileri birincil doğrulayıcı analize alınmaz.',
            'concept_title' => 'İç içe veri yapılarında özyineleme',
            'concept_rationale' => 'Temel Python bilen katılımcıların çoğu döngü ve listeleri bilir ancak iç içe yapıları özyinelemeyle dolaşmayı sistematik olarak öğrenmemiştir. Kavram 20–30 dakikada öğretilebilir; yakın transfer (iç içe listeler) ve uzak transfer (sözlük tabanlı ağaçlar: klasörler, menüler, organizasyon şeması) görevleri üretmeye uygundur. Doğru çözüm, ezberlenmiş bir döngü kalıbı yerine temel durum ve özyinelemeli adımın problem yapısına göre kurulmasını gerektirir.',
            'settings' => config('experiment.protocol_defaults'),
            'system_prompt_direct' => DefaultPrompts::DIRECT,
            'system_prompt_socratic' => DefaultPrompts::SOCRATIC,
            'is_active' => true,
        ]);

        foreach ([...$this->baselineItems(), ...$this->sessionOneTasks(), ...$this->sessionTwoTasks()] as $order => $task) {
            $protocol->tasks()->create([
                'sort_order' => $order,
                'min_reading_seconds' => 0,
                'planning_seconds' => 0,
                'help_allowed' => false,
                'is_active' => true,
                ...$task,
            ]);
        }

        foreach ($this->resourceArticles() as $order => $article) {
            $protocol->resources()->create([...$article, 'sort_order' => $order]);
        }

        foreach ($this->rubric() as $order => $criterion) {
            $protocol->rubricCriteria()->create([...$criterion, 'sort_order' => $order, 'min_score' => 0, 'max_score' => 3]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function baselineItems(): array
    {
        $items = [
            ["Aşağıdaki kod ne yazdırır?\n\n```python\ntoplam = 0\nfor i in range(1, 5):\n    toplam += i\nprint(toplam)\n```", ['10', '15', '4', '9'], 'a'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nx = [3, 7, 1, 9]\nprint(x[1] + x[-1])\n```", ['10', '16', '4', '12'], 'b'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\ndef f(a, b=2):\n    return a * b\n\nprint(f(3) + f(2, 3))\n```", ['12', '10', '9', '15'], 'a'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nn = 7\nif n % 2 == 0:\n    print(\"çift\")\nelif n > 5:\n    print(\"büyük tek\")\nelse:\n    print(\"küçük tek\")\n```", ['çift', 'küçük tek', 'büyük tek', 'Hiçbir şey'], 'c'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nd = {\"a\": 1, \"b\": 2}\nd[\"a\"] += 5\nprint(d[\"a\"] * d[\"b\"])\n```", ['7', '12', '10', 'KeyError'], 'b'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nk = 1\nsayac = 0\nwhile k < 20:\n    k *= 3\n    sayac += 1\nprint(sayac)\n```", ['2', '3', '4', '27'], 'b'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nm = [[1, 2], [3], []]\nprint(len(m), len(m[0]))\n```", ['3 2', '6 2', '3 1', '2 2'], 'a'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nprint(isinstance([1], list), isinstance(5, list))\n```", ['True True', 'False True', 'True False', 'False False'], 'c'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\ndef kare(x):\n    return x * x\n\ndef topla_kareler(liste):\n    s = 0\n    for e in liste:\n        s += kare(e)\n    return s\n\nprint(topla_kareler([1, 2, 3]))\n```", ['6', '9', '14', '36'], 'c'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nsonuc = []\nfor e in [4, 0, 5]:\n    if e:\n        sonuc.append(e * 2)\nprint(sonuc)\n```", ['[8, 0, 10]', '[8, 10]', '[4, 5]', '[]'], 'b'],
        ];

        return array_map(fn (array $item, int $index) => [
            'session_number' => 0,
            'kind' => TaskKind::Baseline,
            'title' => 'Ön test '.($index + 1),
            'prompt' => $item[0],
            'mcq_options' => array_map(fn ($text, $key) => ['key' => $key, 'text' => $text], $item[1], ['a', 'b', 'c', 'd']),
            'mcq_answer' => $item[2],
            'max_score' => 1,
            'time_limit_seconds' => 90,
        ], $items, array_keys($items));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessionOneTasks(): array
    {
        return [
            [
                'session_number' => 1,
                'kind' => TaskKind::Learning,
                'title' => 'İç içe listedeki sayıların toplamı',
                'prompt' => <<<'MD'
                    Bir liste hem sayılar hem de başka listeler içerebilir. İç içe listeler de yine sayı veya liste içerebilir; iç içelik istenilen kadar derin olabilir.

                    `toplam(veri)` fonksiyonunu yazın. Fonksiyon, `veri` içindeki **tüm sayıların** toplamını döndürmelidir.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `[1, 2, 3]` | `6` |
                    | `[1, [2, 3], 4]` | `10` |
                    | `[[1, [2]], [[3]], 4]` | `10` |
                    | `[]` | `0` |

                    İpucu: Bir elemanın liste olup olmadığını `isinstance(eleman, list)` ile kontrol edebilirsiniz.
                    MD,
                'starter_code' => "def toplam(veri):\n    # veri: sayılardan ve/veya iç içe listelerden oluşan bir liste\n    pass\n",
                'reference_solution' => "def toplam(veri):\n    sonuc = 0\n    for eleman in veri:\n        if isinstance(eleman, list):\n            sonuc += toplam(eleman)\n        else:\n            sonuc += eleman\n    return sonuc\n",
                'test_cases' => [
                    ['call' => 'toplam([1, 2, 3])', 'expected' => '6', 'hidden' => false],
                    ['call' => 'toplam([1, [2, 3], 4])', 'expected' => '10', 'hidden' => false],
                    ['call' => 'toplam([[1, [2]], [[3]], 4])', 'expected' => '10', 'hidden' => false],
                    ['call' => 'toplam([])', 'expected' => '0', 'hidden' => false],
                    ['call' => 'toplam([[], [[]]])', 'expected' => '0', 'hidden' => true],
                    ['call' => 'toplam([5, [-2, [7, [1]]]])', 'expected' => '11', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 15,
                'planning_seconds' => 45,
                'time_limit_seconds' => 720,
                'help_allowed' => true,
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::Learning,
                'title' => 'En derin iç içelik düzeyi',
                'prompt' => <<<'MD'
                    `derinlik(veri)` fonksiyonunu yazın. Fonksiyon, listenin kaç kat iç içe olduğunu döndürmelidir. Hiç alt listesi olmayan bir listenin derinliği `1`'dir; boş liste de derinliği `1` olan bir listedir.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `[1, 2]` | `1` |
                    | `[1, [2]]` | `2` |
                    | `[[[]]]` | `3` |
                    | `[1, [2, [3, [4]]], [5]]` | `4` |
                    MD,
                'starter_code' => "def derinlik(veri):\n    pass\n",
                'reference_solution' => "def derinlik(veri):\n    en_derin = 0\n    for eleman in veri:\n        if isinstance(eleman, list):\n            en_derin = max(en_derin, derinlik(eleman))\n    return en_derin + 1\n",
                'test_cases' => [
                    ['call' => 'derinlik([1, 2])', 'expected' => '1', 'hidden' => false],
                    ['call' => 'derinlik([1, [2]])', 'expected' => '2', 'hidden' => false],
                    ['call' => 'derinlik([[[]]])', 'expected' => '3', 'hidden' => false],
                    ['call' => 'derinlik([1, [2, [3, [4]]], [5]])', 'expected' => '4', 'hidden' => false],
                    ['call' => 'derinlik([])', 'expected' => '1', 'hidden' => true],
                    ['call' => 'derinlik([[1], [[2]], 3])', 'expected' => '3', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 15,
                'planning_seconds' => 45,
                'time_limit_seconds' => 720,
                'help_allowed' => true,
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::NearTransfer,
                'title' => 'Çift sayıları sayma',
                'isomorph_group' => 'near-a',
                'prompt' => <<<'MD'
                    `cift_say(veri)` fonksiyonunu yazın. Fonksiyon, iç içe liste içindeki **çift sayıların adedini** döndürmelidir.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `[1, 2, 3, 4]` | `2` |
                    | `[1, [2, 4], [[6, 7]]]` | `3` |
                    | `[[], [1, [3]]]` | `0` |
                    MD,
                'starter_code' => "def cift_say(veri):\n    pass\n",
                'reference_solution' => "def cift_say(veri):\n    adet = 0\n    for eleman in veri:\n        if isinstance(eleman, list):\n            adet += cift_say(eleman)\n        elif eleman % 2 == 0:\n            adet += 1\n    return adet\n",
                'test_cases' => [
                    ['call' => 'cift_say([1, 2, 3, 4])', 'expected' => '2', 'hidden' => false],
                    ['call' => 'cift_say([1, [2, 4], [[6, 7]]])', 'expected' => '3', 'hidden' => false],
                    ['call' => 'cift_say([[], [1, [3]]])', 'expected' => '0', 'hidden' => false],
                    ['call' => 'cift_say([0, [-2, [5, [8]]]])', 'expected' => '3', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 15,
                'planning_seconds' => 30,
                'time_limit_seconds' => 480,
                'help_allowed' => true,
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::FarTransfer,
                'title' => 'Klasörün toplam boyutu',
                'isomorph_group' => 'far-a',
                'prompt' => <<<'MD'
                    Bir klasör sözlükle temsil ediliyor: `"ad"` klasörün adı, `"dosyalar"` klasördeki dosyaların boyutları (KB), `"alt"` ise alt klasörlerin listesi. Alt klasörler de aynı yapıdadır.

                    ```python
                    proje = {
                        "ad": "proje", "dosyalar": [120, 40], "alt": [
                            {"ad": "src", "dosyalar": [300], "alt": []},
                            {"ad": "docs", "dosyalar": [], "alt": [
                                {"ad": "img", "dosyalar": [80, 20], "alt": []}
                            ]}
                        ]
                    }
                    ```

                    `toplam_boyut(klasor)` fonksiyonunu yazın. Fonksiyon, klasördeki ve **tüm alt klasörlerindeki** dosyaların toplam boyutunu döndürmelidir. Yukarıdaki örnek için sonuç `560` olmalıdır.
                    MD,
                'starter_code' => "def toplam_boyut(klasor):\n    pass\n",
                'reference_solution' => "def toplam_boyut(klasor):\n    toplam = sum(klasor[\"dosyalar\"])\n    for alt in klasor[\"alt\"]:\n        toplam += toplam_boyut(alt)\n    return toplam\n",
                'test_cases' => [
                    ['call' => 'toplam_boyut({"ad": "a", "dosyalar": [5, 7], "alt": []})', 'expected' => '12', 'hidden' => false],
                    ['call' => 'toplam_boyut({"ad": "proje", "dosyalar": [120, 40], "alt": [{"ad": "src", "dosyalar": [300], "alt": []}, {"ad": "docs", "dosyalar": [], "alt": [{"ad": "img", "dosyalar": [80, 20], "alt": []}]}]})', 'expected' => '560', 'hidden' => false],
                    ['call' => 'toplam_boyut({"ad": "bos", "dosyalar": [], "alt": [{"ad": "x", "dosyalar": [], "alt": []}]})', 'expected' => '0', 'hidden' => false],
                    ['call' => 'toplam_boyut({"ad": "k", "dosyalar": [1], "alt": [{"ad": "a", "dosyalar": [2], "alt": [{"ad": "b", "dosyalar": [3], "alt": [{"ad": "c", "dosyalar": [4], "alt": []}]}]}]})', 'expected' => '10', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 20,
                'planning_seconds' => 30,
                'time_limit_seconds' => 600,
                'help_allowed' => true,
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::FarTransfer,
                'title' => 'Menü derinliği',
                'isomorph_group' => 'far-b',
                'prompt' => <<<'MD'
                    Bir uygulamanın menüsü sözlüklerle temsil ediliyor. Her menü öğesinin bir `"baslik"` alanı ve alt öğelerini tutan `"alt"` listesi var.

                    ```python
                    menu = {"baslik": "Ana", "alt": [
                        {"baslik": "Dosya", "alt": [{"baslik": "Aç", "alt": []}]},
                        {"baslik": "Yardım", "alt": []}
                    ]}
                    ```

                    `menu_derinligi(menu)` fonksiyonunu yazın. Fonksiyon, en üst öğeden en alttaki öğeye kadar en uzun yolun kaç öğeden oluştuğunu döndürmelidir. Örnekte `Ana → Dosya → Aç` yolu en uzun yoldur, sonuç `3`'tür. Alt öğesi olmayan tek bir öğenin derinliği `1`'dir.
                    MD,
                'starter_code' => "def menu_derinligi(menu):\n    pass\n",
                'reference_solution' => "def menu_derinligi(menu):\n    en_derin = 0\n    for alt in menu[\"alt\"]:\n        en_derin = max(en_derin, menu_derinligi(alt))\n    return en_derin + 1\n",
                'test_cases' => [
                    ['call' => 'menu_derinligi({"baslik": "Tek", "alt": []})', 'expected' => '1', 'hidden' => false],
                    ['call' => 'menu_derinligi({"baslik": "Ana", "alt": [{"baslik": "Dosya", "alt": [{"baslik": "Aç", "alt": []}]}, {"baslik": "Yardım", "alt": []}]})', 'expected' => '3', 'hidden' => false],
                    ['call' => 'menu_derinligi({"baslik": "A", "alt": [{"baslik": "B", "alt": []}, {"baslik": "C", "alt": []}]})', 'expected' => '2', 'hidden' => false],
                    ['call' => 'menu_derinligi({"baslik": "A", "alt": [{"baslik": "B", "alt": []}, {"baslik": "C", "alt": [{"baslik": "D", "alt": [{"baslik": "E", "alt": []}]}]}]})', 'expected' => '4', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 20,
                'planning_seconds' => 30,
                'time_limit_seconds' => 600,
                'help_allowed' => true,
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::Debugging,
                'title' => 'En büyük sayıyı bulan fonksiyondaki hata',
                'isomorph_group' => 'debug-a',
                'prompt' => <<<'MD'
                    Aşağıdaki `en_buyuk(veri)` fonksiyonu iç içe bir listedeki en büyük sayıyı bulmak için yazılmış, ancak bazı girdilerde yanlış sonuç veriyor veya hata veriyor.

                    Kodu çalıştırıp testleri inceleyin, hatayı bulun ve düzeltin. Listede en az bir sayı olduğunu varsayabilirsiniz.
                    MD,
                'starter_code' => "def en_buyuk(veri):\n    en = veri[0]\n    for eleman in veri:\n        if isinstance(eleman, list):\n            en_buyuk(eleman)\n        elif eleman > en:\n            en = eleman\n    return en\n",
                'reference_solution' => "def en_buyuk(veri):\n    en = None\n    for eleman in veri:\n        aday = en_buyuk(eleman) if isinstance(eleman, list) else eleman\n        if aday is not None and (en is None or aday > en):\n            en = aday\n    return en\n",
                'test_cases' => [
                    ['call' => 'en_buyuk([3, 9, 2])', 'expected' => '9', 'hidden' => false],
                    ['call' => 'en_buyuk([1, [12, 4], 7])', 'expected' => '12', 'hidden' => false],
                    ['call' => 'en_buyuk([[5, [20]], 3])', 'expected' => '20', 'hidden' => false],
                    ['call' => 'en_buyuk([[-4], -9])', 'expected' => '-4', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 20,
                'planning_seconds' => 30,
                'time_limit_seconds' => 480,
                'help_allowed' => true,
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::Explanation,
                'title' => 'Klasör boyutu çözümünün açıklaması',
                'isomorph_group' => 'explain-a',
                'prompt' => <<<'MD'
                    "Klasörün toplam boyutu" görevinde çözümün nasıl çalışıyor? Kod yazmadan, kendi cümlelerinizle açıklayın:

                    - Fonksiyon bir klasörü nasıl işliyor, alt klasörlere nasıl ulaşıyor?
                    - Fonksiyon ne zaman durur, neden sonsuza kadar çalışmaz?
                    - Aynı fikir başka hangi tür problemlerde işe yarar?
                    - Hangi girdilerde çözüm sorun çıkarabilir?
                    MD,
                'max_score' => 12,
                'time_limit_seconds' => 420,
            ],
        ];
    }

    /**
     * Session 2 starts with the far-transfer items so the primary outcome is
     * measured before any other task can act as a reminder.
     *
     * @return list<array<string, mixed>>
     */
    private function sessionTwoTasks(): array
    {
        return [
            [
                'session_number' => 2,
                'kind' => TaskKind::FarTransfer,
                'title' => 'Ekibin toplam maaşı',
                'isomorph_group' => 'far-a',
                'prompt' => <<<'MD'
                    Bir şirketin organizasyon şeması sözlüklerle temsil ediliyor: `"isim"`, `"maas"` (bin TL) ve kişinin doğrudan yönettiği kişileri tutan `"ekip"` listesi. Ekipteki her kişi de aynı yapıdadır.

                    ```python
                    sirket = {"isim": "Ayşe", "maas": 50, "ekip": [
                        {"isim": "Can", "maas": 30, "ekip": []},
                        {"isim": "Deniz", "maas": 35, "ekip": [
                            {"isim": "Ece", "maas": 25, "ekip": []}
                        ]}
                    ]}
                    ```

                    `toplam_maas(kisi)` fonksiyonunu yazın. Fonksiyon, kişinin kendisi ve altında çalışan **herkesin** maaşlarının toplamını döndürmelidir. Örnek için sonuç `140` olmalıdır.
                    MD,
                'starter_code' => "def toplam_maas(kisi):\n    pass\n",
                'reference_solution' => "def toplam_maas(kisi):\n    toplam = kisi[\"maas\"]\n    for calisan in kisi[\"ekip\"]:\n        toplam += toplam_maas(calisan)\n    return toplam\n",
                'test_cases' => [
                    ['call' => 'toplam_maas({"isim": "Tek", "maas": 40, "ekip": []})', 'expected' => '40', 'hidden' => false],
                    ['call' => 'toplam_maas({"isim": "Ayşe", "maas": 50, "ekip": [{"isim": "Can", "maas": 30, "ekip": []}, {"isim": "Deniz", "maas": 35, "ekip": [{"isim": "Ece", "maas": 25, "ekip": []}]}]})', 'expected' => '140', 'hidden' => false],
                    ['call' => 'toplam_maas({"isim": "A", "maas": 10, "ekip": [{"isim": "B", "maas": 0, "ekip": []}]})', 'expected' => '10', 'hidden' => false],
                    ['call' => 'toplam_maas({"isim": "A", "maas": 1, "ekip": [{"isim": "B", "maas": 2, "ekip": [{"isim": "C", "maas": 3, "ekip": [{"isim": "D", "maas": 4, "ekip": []}]}]}]})', 'expected' => '10', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 20,
                'planning_seconds' => 30,
                'time_limit_seconds' => 600,
            ],
            [
                'session_number' => 2,
                'kind' => TaskKind::FarTransfer,
                'title' => 'Yorum zincirinin uzunluğu',
                'isomorph_group' => 'far-b',
                'prompt' => <<<'MD'
                    Bir forumdaki yorumlar ve yanıtları sözlüklerle temsil ediliyor. Her yorumun bir `"metin"` alanı ve kendisine verilen yanıtları tutan `"yanitlar"` listesi var.

                    ```python
                    yorum = {"metin": "Soru", "yanitlar": [
                        {"metin": "Yanıt 1", "yanitlar": []},
                        {"metin": "Yanıt 2", "yanitlar": [
                            {"metin": "Katılıyorum", "yanitlar": []}
                        ]}
                    ]}
                    ```

                    `zincir_uzunlugu(yorum)` fonksiyonunu yazın. Fonksiyon, ilk yorumdan başlayıp en uzun yanıt zincirinin kaç yorumdan oluştuğunu döndürmelidir. Örnekte `Soru → Yanıt 2 → Katılıyorum` en uzun zincirdir, sonuç `3`'tür. Yanıtı olmayan bir yorum için sonuç `1`'dir.
                    MD,
                'starter_code' => "def zincir_uzunlugu(yorum):\n    pass\n",
                'reference_solution' => "def zincir_uzunlugu(yorum):\n    en_uzun = 0\n    for yanit in yorum[\"yanitlar\"]:\n        en_uzun = max(en_uzun, zincir_uzunlugu(yanit))\n    return en_uzun + 1\n",
                'test_cases' => [
                    ['call' => 'zincir_uzunlugu({"metin": "x", "yanitlar": []})', 'expected' => '1', 'hidden' => false],
                    ['call' => 'zincir_uzunlugu({"metin": "Soru", "yanitlar": [{"metin": "Yanıt 1", "yanitlar": []}, {"metin": "Yanıt 2", "yanitlar": [{"metin": "Katılıyorum", "yanitlar": []}]}]})', 'expected' => '3', 'hidden' => false],
                    ['call' => 'zincir_uzunlugu({"metin": "a", "yanitlar": [{"metin": "b", "yanitlar": []}, {"metin": "c", "yanitlar": []}]})', 'expected' => '2', 'hidden' => false],
                    ['call' => 'zincir_uzunlugu({"metin": "a", "yanitlar": [{"metin": "b", "yanitlar": [{"metin": "c", "yanitlar": [{"metin": "d", "yanitlar": []}]}]}, {"metin": "e", "yanitlar": []}]})', 'expected' => '4', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 20,
                'planning_seconds' => 30,
                'time_limit_seconds' => 600,
            ],
            [
                'session_number' => 2,
                'kind' => TaskKind::NearTransfer,
                'title' => 'Negatif sayıları sayma',
                'isomorph_group' => 'near-a',
                'prompt' => <<<'MD'
                    `negatif_say(veri)` fonksiyonunu yazın. Fonksiyon, iç içe liste içindeki **negatif sayıların adedini** döndürmelidir.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `[-1, 2, -3]` | `2` |
                    | `[1, [-2, 4], [[-6, 7]]]` | `2` |
                    | `[[], [0, [3]]]` | `0` |
                    MD,
                'starter_code' => "def negatif_say(veri):\n    pass\n",
                'reference_solution' => "def negatif_say(veri):\n    adet = 0\n    for eleman in veri:\n        if isinstance(eleman, list):\n            adet += negatif_say(eleman)\n        elif eleman < 0:\n            adet += 1\n    return adet\n",
                'test_cases' => [
                    ['call' => 'negatif_say([-1, 2, -3])', 'expected' => '2', 'hidden' => false],
                    ['call' => 'negatif_say([1, [-2, 4], [[-6, 7]]])', 'expected' => '2', 'hidden' => false],
                    ['call' => 'negatif_say([[], [0, [3]]])', 'expected' => '0', 'hidden' => false],
                    ['call' => 'negatif_say([-5, [[-1, [-2]], 0]])', 'expected' => '3', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 15,
                'planning_seconds' => 30,
                'time_limit_seconds' => 480,
            ],
            [
                'session_number' => 2,
                'kind' => TaskKind::Debugging,
                'title' => 'Düzleştirme fonksiyonundaki hata',
                'isomorph_group' => 'debug-a',
                'prompt' => <<<'MD'
                    Aşağıdaki `duzlestir(veri)` fonksiyonu iç içe bir listeyi tek düzeyli bir listeye çevirmek için yazılmış. Örneğin `[1, [2, [3]]]` için `[1, 2, 3]` döndürmesi gerekiyor, ancak beklenen sonucu vermiyor.

                    Kodu çalıştırıp testleri inceleyin, hatayı bulun ve düzeltin.
                    MD,
                'starter_code' => "def duzlestir(veri):\n    sonuc = []\n    for eleman in veri:\n        if isinstance(eleman, list):\n            sonuc.append(duzlestir(eleman))\n        else:\n            sonuc.append(eleman)\n    return sonuc\n",
                'reference_solution' => "def duzlestir(veri):\n    sonuc = []\n    for eleman in veri:\n        if isinstance(eleman, list):\n            sonuc.extend(duzlestir(eleman))\n        else:\n            sonuc.append(eleman)\n    return sonuc\n",
                'test_cases' => [
                    ['call' => 'duzlestir([1, 2])', 'expected' => '[1, 2]', 'hidden' => false],
                    ['call' => 'duzlestir([1, [2, 3]])', 'expected' => '[1, 2, 3]', 'hidden' => false],
                    ['call' => 'duzlestir([[1, [2]], [], 3])', 'expected' => '[1, 2, 3]', 'hidden' => false],
                    ['call' => 'duzlestir([[[[4]]], [5, [6]]])', 'expected' => '[4, 5, 6]', 'hidden' => true],
                ],
                'max_score' => 10,
                'min_reading_seconds' => 20,
                'planning_seconds' => 30,
                'time_limit_seconds' => 480,
            ],
            [
                'session_number' => 2,
                'kind' => TaskKind::DelayedRecall,
                'title' => 'Hatırlama 1',
                'prompt' => 'Özyinelemeli bir fonksiyonda temel durum (durma koşulu) hiç sağlanmazsa ne olur?',
                'mcq_options' => [
                    ['key' => 'a', 'text' => 'Fonksiyon kendini durmadan çağırır ve sonunda RecursionError oluşur.'],
                    ['key' => 'b', 'text' => 'Fonksiyon otomatik olarak None döndürür.'],
                    ['key' => 'c', 'text' => 'Python özyinelemeyi kendiliğinden bir döngüye çevirir.'],
                    ['key' => 'd', 'text' => 'Yalnızca listenin ilk elemanı işlenir.'],
                ],
                'mcq_answer' => 'a',
                'max_score' => 1,
                'time_limit_seconds' => 90,
            ],
            [
                'session_number' => 2,
                'kind' => TaskKind::DelayedRecall,
                'title' => 'Hatırlama 2',
                'prompt' => "Aşağıdaki kod ne yazdırır?\n\n```python\ndef f(veri):\n    s = 0\n    for e in veri:\n        if isinstance(e, list):\n            s += f(e)\n        else:\n            s += 1\n    return s\n\nprint(f([1, [2, [3, 4]], []]))\n```",
                'mcq_options' => [
                    ['key' => 'a', 'text' => '3'],
                    ['key' => 'b', 'text' => '4'],
                    ['key' => 'c', 'text' => '5'],
                    ['key' => 'd', 'text' => '6'],
                ],
                'mcq_answer' => 'b',
                'max_score' => 1,
                'time_limit_seconds' => 120,
            ],
            [
                'session_number' => 2,
                'kind' => TaskKind::Explanation,
                'title' => 'Maaş toplamı çözümünün açıklaması',
                'isomorph_group' => 'explain-a',
                'prompt' => <<<'MD'
                    "Ekibin toplam maaşı" görevinde çözümün nasıl çalışıyor? Kod yazmadan, kendi cümlelerinizle açıklayın:

                    - Fonksiyon bir kişiyi nasıl işliyor, ekibindeki kişilere nasıl ulaşıyor?
                    - Fonksiyon ne zaman durur, neden sonsuza kadar çalışmaz?
                    - Aynı fikir başka hangi tür problemlerde işe yarar?
                    - Hangi girdilerde çözüm sorun çıkarabilir?
                    MD,
                'max_score' => 12,
                'time_limit_seconds' => 420,
            ],
        ];
    }

    /**
     * Reference material for the standard-resource condition. Examples are
     * deliberately different from every task in the battery.
     *
     * @return list<array<string, string>>
     */
    private function resourceArticles(): array
    {
        return [
            [
                'section' => 'concept',
                'title' => 'Özyineleme nedir?',
                'body' => <<<'MD'
                    **Özyineleme**, bir fonksiyonun bir problemi çözerken aynı problemin daha küçük bir parçası için kendisini çağırmasıdır.

                    Her özyinelemeli fonksiyonda iki parça bulunur:

                    1. **Temel durum (durma koşulu):** Problemin artık bölünmeden doğrudan çözülebildiği durum. Fonksiyon burada kendini çağırmaz.
                    2. **Özyinelemeli adım:** Fonksiyonun problemi küçültüp kendisini çağırdığı ve dönen sonucu kullandığı kısım.

                    Temel durum yoksa ya da özyinelemeli adım problemi gerçekten küçültmüyorsa fonksiyon kendini durmadan çağırır ve Python `RecursionError` verir.
                    MD,
            ],
            [
                'section' => 'concept',
                'title' => 'İç içe yapılar ve özyineleme',
                'body' => <<<'MD'
                    Listeler başka listeler, sözlükler başka sözlükler içerebilir. Bu tür yapılara **iç içe** ya da **ağaç biçimli** yapılar denir.

                    İç içeliğin kaç katman olacağı önceden bilinmiyorsa sabit sayıda iç içe `for` döngüsü yazmak işe yaramaz. Bunun yerine şu düşünce kullanılır:

                    > Bir eleman basit bir değerse onu doğrudan işle. Eleman kendisi de aynı türden bir yapıysa, onu işlemek için **aynı fonksiyonu** çağır ve dönen sonucu kullan.

                    Bu durumda temel durum genellikle "basit değer" ya da "alt elemanı olmayan yapı" durumudur.
                    MD,
            ],
            [
                'section' => 'examples',
                'title' => 'Örnek: Faktöriyel',
                'body' => <<<'MD'
                    ```python
                    def faktoriyel(n):
                        if n <= 1:          # temel durum
                            return 1
                        return n * faktoriyel(n - 1)   # özyinelemeli adım
                    ```

                    `faktoriyel(4)` çağrısı şu şekilde açılır: `4 * faktoriyel(3)` → `4 * 3 * faktoriyel(2)` → `4 * 3 * 2 * faktoriyel(1)` → `4 * 3 * 2 * 1 = 24`.
                    MD,
            ],
            [
                'section' => 'examples',
                'title' => 'Örnek: İç içe listede bir değeri arama',
                'body' => <<<'MD'
                    Bir değerin iç içe liste içinde herhangi bir yerde geçip geçmediğini bulan fonksiyon:

                    ```python
                    def iceriyor_mu(veri, aranan):
                        for eleman in veri:
                            if isinstance(eleman, list):
                                if iceriyor_mu(eleman, aranan):
                                    return True
                            elif eleman == aranan:
                                return True
                        return False
                    ```

                    `iceriyor_mu([1, [2, [3]]], 3)` → `True`. Alt listeye inildiğinde fonksiyon kendini çağırır ve dönen sonuca göre karar verir.
                    MD,
            ],
            [
                'section' => 'syntax',
                'title' => 'Tür kontrolü ve sözlük erişimi',
                'body' => <<<'MD'
                    | İfade | Anlamı |
                    |---|---|
                    | `isinstance(x, list)` | `x` bir liste ise `True` |
                    | `isinstance(x, dict)` | `x` bir sözlük ise `True` |
                    | `sozluk["anahtar"]` | Anahtarın değerini verir, anahtar yoksa `KeyError` |
                    | `sozluk.get("anahtar", varsayilan)` | Anahtar yoksa varsayılanı verir |
                    | `max(a, b)` | İki değerden büyüğü |
                    | `liste.append(x)` | `x`'i listeye **tek eleman** olarak ekler |
                    | `liste.extend(diger)` | `diger` listesinin **elemanlarını** tek tek ekler |
                    MD,
            ],
            [
                'section' => 'common_errors',
                'title' => 'Sık yapılan hatalar',
                'body' => <<<'MD'
                    - **Dönen değeri kullanmamak:** Fonksiyonu kendi içinde çağırıp sonucunu bir değişkene eklememek veya döndürmemek. Çağrı yapılır ama sonuç kaybolur.
                    - **Temel durumu unutmak:** Fonksiyonun ne zaman durduğu tanımlanmazsa `RecursionError` oluşur.
                    - **Problemi küçültmemek:** Fonksiyonu aynı girdiyle tekrar çağırmak.
                    - **`append` ile `extend` karışıklığı:** Alt sonuç bir listeyse `append` onu iç içe ekler, `extend` elemanlarını ekler.
                    - **Boş yapıları düşünmemek:** Boş liste veya alt elemanı olmayan yapı için ne döndürüleceği belirlenmelidir.
                    - **İlk elemanın türünü varsaymak:** `veri[0]` bir liste de olabilir ya da liste boş olabilir.
                    MD,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rubric(): array
    {
        return [
            [
                'key' => 'conceptual_accuracy',
                'label' => 'Kavramsal doğruluk',
                'description' => 'Özyineleme ve iç içe yapı kavramlarını doğru kullanıyor mu?',
                'anchors' => ['0' => 'Yanlış veya ilgisiz', '1' => 'Kısmen doğru, önemli kavram hatası var', '2' => 'Doğru, küçük eksikler var', '3' => 'Tam ve doğru'],
            ],
            [
                'key' => 'solution_logic',
                'label' => 'Çözüm mantığını açıklama',
                'description' => 'Temel durumu ve özyinelemeli adımı, alt sonuçların nasıl birleştirildiğini açıklıyor mu?',
                'anchors' => ['0' => 'Açıklama yok', '1' => 'Yalnızca ne yaptığını söylüyor', '2' => 'Adımları açıklıyor, durma koşulu belirsiz', '3' => 'Temel durum, özyinelemeli adım ve birleştirme açık'],
            ],
            [
                'key' => 'generalization',
                'label' => 'Genellenebilirlik',
                'description' => 'Fikrin başka problem türlerine nasıl uygulanacağını gösterebiliyor mu?',
                'anchors' => ['0' => 'Yok', '1' => 'Yüzeysel benzer bir örnek', '2' => 'Farklı bir bağlam doğru eşleştirilmiş', '3' => 'Genel ilkeyi açıkça soyutlamış'],
            ],
            [
                'key' => 'edge_cases',
                'label' => 'Sınır durumlarını fark etme',
                'description' => 'Boş yapı, çok derin iç içelik, eksik anahtar gibi durumları fark ediyor mu?',
                'anchors' => ['0' => 'Hiç değinilmemiş', '1' => 'Belirsiz bir değinme', '2' => 'Bir sınır durumu doğru', '3' => 'Birden fazla sınır durumu ve etkisi doğru'],
            ],
        ];
    }
}
