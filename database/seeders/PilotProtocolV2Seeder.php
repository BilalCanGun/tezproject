<?php

namespace Database\Seeders;

use App\Enums\ProtocolPhase;
use App\Enums\TaskKind;
use App\Models\ProtocolVersion;
use App\Services\Assistance\DefaultPrompts;
use Illuminate\Database\Seeder;

/**
 * Draft pilot protocol for the recommended target concept: finite state
 * machines for parsing text and event sequences. Recursion (PILOT-v1) is
 * the backup concept. Every task carries its profile and scoring key; near
 * transfer, far transfer and debugging come in isomorphic A/B forms.
 *
 * The protocol is created inactive so it never replaces the running one.
 */
class PilotProtocolV2Seeder extends Seeder
{
    public const CODE = 'PILOT-v2';

    /**
     * Model answers ("first answers") for the explanation tasks, shown to raters
     * next to the expected elements.
     */
    public const MODEL_ANSWER_SESSION_ONE = 'Durum makinesi girdiyi baştan sona bir kez okur ve her an sınırlı sayıdaki durumdan birindedir; durum, şu ana kadar okunanların özetidir. Her yeni karakter ya da olayda, o anki duruma ve gelen girdiye bakılarak bir geçiş kuralıyla yeni duruma geçilir. Okumaya başlamadan önce başlangıç durumundayızdır; girdi bitince son durum kabul durumlarından biriyse sonuç geçerlidir. Hangi durumlara ihtiyaç olduğuna karar vermek için "okurken hangi farklı aşamalarda olabilirim?" diye düşünürüm; örneğin ürün kodunda "harf okuyorum", "tireyi gördüm", "rakam okuyorum" aşamalarını ayırır ve her aşamada hangi karakterin nereye götürdüğünü bir tabloya yazarım. Kurala uymayan bir karakter gelirse makine hata durumuna geçer ve oradan çıkmaz. Aynı fikir yalnızca metinlerde değil, düğmelere basma, cihaz komutları ya da bir olay dizisini işleyen programlarda da kullanılabilir; önemli olan, bir girdinin anlamının önceki girdilere bağlı olmasıdır.';

    public const MODEL_ANSWER_SESSION_TWO = 'Durumlar KIRMIZI, YEŞİL, SARI ve YANIP SÖNEN SARI olur; başlangıç durumu KIRMIZI\'dır. "Süre doldu" olayı KIRMIZI → YEŞİL → SARI → KIRMIZI döngüsünü bir adım ilerletir. "Arıza" olayı hangi durumda gelirse gelsin lambayı YANIP SÖNEN SARI durumuna götürür; bu durumdayken "süre doldu" olayları durumu değiştirmez. "Düzeltildi" olayı YANIP SÖNEN SARI\'dan KIRMIZI\'ya döndürür; diğer durumlarda gelirse yok sayılır. Beklenmeyen bir olay gelirse durum değişmez (gerekirse hata olarak sayılır). Program durumu KIRMIZI olarak başlatır, olay listesini sırayla okur ve her olayda (mevcut durum, olay) çiftine göre bir geçiş tablosundan yeni durumu bulur; tabloda olmayan çiftlerde durumu değiştirmez.';

    public function run(): void
    {
        if (ProtocolVersion::where('code', self::CODE)->exists()) {
            return;
        }

        $protocol = ProtocolVersion::create([
            'code' => self::CODE,
            'name' => 'Pilot uygulama — sürüm 2 (durum makinesi, taslak)',
            'phase' => ProtocolPhase::Pilot,
            'description' => 'Önerilen ana kavram için taslak görev bataryası: ön test, kavram ön kontrolü, ortak öğrenme içeriği, A/B formlu yakın/uzak transfer ve hata bulma görevleri, ayrı gecikmeli hatırlama. Danışman onayından önce veri toplamada kullanılmaz.',
            'concept_title' => 'Durum makinesiyle metin ve olay dizisi işleme',
            'concept_rationale' => 'Temel Python bilen katılımcıların çoğu döngü ve koşulları bilir, ancak girdiyi bir durum değişkeni ve geçiş kurallarıyla işlemeyi sistematik olarak öğrenmemiştir (özyinelemeden daha az bilinir). Ön bilgi olarak yalnızca döngü, koşul ve metin işlemleri gerekir; kavram 20–30 dakikada öğretilebilir. Yakın transfer (metin biçimi doğrulama) ve uzak transfer (cihaz komutları, tırnaklı alan ayırma gibi ilkenin uygulanabileceğinin fark edilmesi gereken problemler) üretmeye uygundur. Çözüm, durumların ve geçişlerin kodlamadan önce tasarlanmasını gerektirdiği için gerçek bir sessiz planlama penceresi oluşturur. Yedek kavram: özyineleme (PILOT-v1).',
            'core_lesson' => $this->coreLesson(),
            'learning_objectives' => $this->learningObjectives(),
            'settings' => config('experiment.protocol_defaults'),
            'system_prompt_direct' => DefaultPrompts::DIRECT,
            'system_prompt_socratic' => DefaultPrompts::SOCRATIC,
            'is_active' => false,
        ]);

        $tasks = [
            ...$this->baselineItems(),
            ...$this->conceptPrecheckItems(),
            ...$this->sessionOneTasks(),
            ...$this->formTasks(),
            ...$this->sessionTwoTasks(),
        ];

        foreach ($tasks as $order => $task) {
            $protocol->tasks()->create([
                'sort_order' => $order,
                'session_number' => null,
                'form' => null,
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
     * @return list<array{key: string, label: string}>
     */
    private function learningObjectives(): array
    {
        return [
            ['key' => 'tanima', 'label' => 'Bir problemin durumlar ve geçişlerle modellenebileceğini tanıma'],
            ['key' => 'aciklama', 'label' => 'Durum makinesinin çalışma mantığını (durum, girdi, geçiş, kabul) açıklama'],
            ['key' => 'uygulama', 'label' => 'Basit bir metin işleme problemini durum makinesiyle kodlama'],
            ['key' => 'hata_fark', 'label' => 'Hatalı durum makinesi kullanımını fark edip düzeltme'],
            ['key' => 'genelleme', 'label' => 'İlkeyi metin dışı, yeni bir probleme genelleme'],
        ];
    }

    private function coreLesson(): string
    {
        return <<<'MD'
            # Durum makinesi ile girdiyi adım adım işlemek

            Bazı problemlerde bir girdinin ne anlama geldiği, **daha önce ne okunduğuna** bağlıdır. Örneğin `-12` metninde `-` işareti yalnızca en başta anlamlıdır; `1-2` metninde ise aynı karakter hatadır. Bu tür problemleri düzenli biçimde çözmenin bir yolu **durum makinesi** kullanmaktır.

            ## Temel fikir

            Durum makinesi, girdiyi baştan sona **bir kez** okur ve her an şu soruya cevap verir: *"Şu ana kadar okuduklarıma göre neredeyim?"* Bu cevaba **durum** denir.

            | Kavram | Anlamı |
            |---|---|
            | **Durum** | Şu ana kadar okunanların özeti. Sayısı sınırlıdır ve önceden belirlenir. |
            | **Başlangıç durumu** | Hiçbir şey okunmadan önceki durum. |
            | **Girdi** | Sıradaki karakter veya olay. |
            | **Geçiş** | "Bu durumdayken bu girdi gelirse yeni durum şudur" kuralı. |
            | **Kabul durumları** | Girdi bittiğinde bu durumlardan birindeysek sonuç geçerlidir. |
            | **Hata durumu** | Kurallara uymayan bir girdi geldiğinde gidilen ve bir daha çıkılmayan durum. |

            Bir sonraki durumu **yalnızca iki şey** belirler: mevcut durum ve okunan girdi.

            ## Örnek 1: İşaretli tam sayı doğrulama

            Kural: İsteğe bağlı tek bir `+` veya `-` işareti, ardından en az bir rakam. `42`, `-7`, `+105` geçerli; `""`, `+`, `4-2`, `--3` geçersiz.

            Durumlar: `BASLA`, `ISARET`, `RAKAM`, `HATA`. Kabul durumu: yalnızca `RAKAM`.

            | Mevcut durum | `+` veya `-` | Rakam | Diğer |
            |---|---|---|---|
            | `BASLA` | `ISARET` | `RAKAM` | `HATA` |
            | `ISARET` | `HATA` | `RAKAM` | `HATA` |
            | `RAKAM` | `HATA` | `RAKAM` | `HATA` |
            | `HATA` | `HATA` | `HATA` | `HATA` |

            ```python
            def gecerli_tamsayi(metin):
                durum = "BASLA"
                for karakter in metin:
                    if durum == "BASLA":
                        if karakter in "+-":
                            durum = "ISARET"
                        elif karakter.isdigit():
                            durum = "RAKAM"
                        else:
                            durum = "HATA"
                    elif durum in ("ISARET", "RAKAM"):
                        durum = "RAKAM" if karakter.isdigit() else "HATA"
                return durum == "RAKAM"
            ```

            `"-7"` için: `BASLA` → (`-`) → `ISARET` → (`7`) → `RAKAM`. Girdi bitti, durum kabul durumu: **geçerli**.
            `"+"` için: `BASLA` → `ISARET`. Girdi bitti ama `ISARET` kabul durumu değil: **geçersiz**.

            ## Örnek 2: Okurken bir şey üreten makine

            Durum makinesi yalnızca "geçerli/geçersiz" demek için değil, okurken bir sonuç üretmek için de kullanılır. Bir metindeki kelimeleri saymak için iki durum yeter: `DISARIDA` (boşluktayız) ve `ICERIDE` (bir kelimenin içindeyiz). Sayaç **yalnızca** `DISARIDA` → `ICERIDE` geçişinde artar.

            ```python
            def kelime_say(metin):
                durum = "DISARIDA"
                sayac = 0
                for karakter in metin:
                    if karakter == " ":
                        durum = "DISARIDA"
                    elif durum == "DISARIDA":
                        durum = "ICERIDE"
                        sayac += 1
                return sayac
            ```

            ## Bir durum makinesini kurarken izlenecek adımlar

            1. **Önce kodu değil, durumları düşünün:** "Girdiyi okurken hangi farklı aşamalarda olabilirim?"
            2. Başlangıç durumunu ve kabul durumlarını belirleyin.
            3. Her durum için her girdi türünde ne olacağını (geçişi) belirleyin. Bir tablo çizmek çok yardımcı olur.
            4. Kurallara uymayan girdiler için bir hata durumu ekleyin.
            5. Kod kalıbı: `durum = başlangıç` → her girdi için durumu güncelle (gerekirse bir şey üret) → sonda son duruma bak.

            ## Sık yapılan hatalar

            - Döngü içinde durumu **güncellemeyi unutmak**.
            - Bir durumda bazı girdileri hiç düşünmemek (eksik geçiş).
            - Girdi bittikten sonra son durumun kabul durumu olup olmadığını **kontrol etmemek**.
            - Sayacı veya çıktıyı yanlış geçişte güncellemek (ör. kelimenin her harfinde saymak).

            ## Ne zaman işe yarar?

            Bir girdinin anlamı **önceki girdilere bağlıysa** ve bu geçmişin tamamını değil yalnızca bir özetini (durumu) bilmek yetiyorsa durum makinesi iyi bir yaklaşımdır. Girdi karakterler olabileceği gibi düğmeye basma, komut veya olay dizisi de olabilir.
            MD;
    }

    /**
     * General programming skill (stratification covariate): 10 items that do
     * not touch the target concept.
     *
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
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nmetin = \"a1b22c\"\nsayac = 0\nfor k in metin:\n    if k.isdigit():\n        sayac += 1\nprint(sayac)\n```", ['2', '3', '6', '0'], 'b'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nkelime = \"kitap\"\nprint(kelime[0] + kelime[-1])\n```", ['ki', 'kp', 'pk', 'ka'], 'b'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\ndef kare(x):\n    return x * x\n\ndef topla_kareler(liste):\n    s = 0\n    for e in liste:\n        s += kare(e)\n    return s\n\nprint(topla_kareler([1, 2, 3]))\n```", ['6', '9', '14', '36'], 'c'],
            ["Aşağıdaki kod ne yazdırır?\n\n```python\nsonuc = []\nfor e in [4, 0, 5]:\n    if e:\n        sonuc.append(e * 2)\nprint(sonuc)\n```", ['[8, 0, 10]', '[8, 10]', '[4, 5]', '[]'], 'b'],
        ];

        return array_map(fn (array $item, int $index) => [
            'session_number' => 0,
            'kind' => TaskKind::Baseline,
            'title' => 'Ön test '.($index + 1),
            'prompt' => $item[0],
            'mcq_options' => $this->options($item[1]),
            'mcq_answer' => $item[2],
            'max_score' => 1,
            'time_limit_seconds' => 90,
            'scoring_key' => [
                'measures' => 'baseline',
                'expected_approach' => 'Kodu satır satır izleyerek çıktıyı bulmak.',
                'difficulty' => $index < 4 ? 'kolay' : 'orta',
                'estimated_minutes' => 1,
                'category_rationale' => 'Genel programlama becerisini (değişken, döngü, koşul, fonksiyon, liste, sözlük, metin) ölçer; hedef kavrama değinmez.',
            ],
        ], $items, array_keys($items));
    }

    /**
     * Short check of prior knowledge of the target concept. It asks for
     * recognition only and offers an explicit "I don't know" option so the
     * items do not teach the concept or invite guessing.
     *
     * @return list<array<string, mixed>>
     */
    private function conceptPrecheckItems(): array
    {
        $dontKnow = 'Bu kavramı bilmiyorum / emin değilim';
        $items = [
            [
                'Yazılımda **sonlu durum makinesi** (finite state machine) terimi neyi ifade eder?',
                [
                    'Belleği sınırlı olan bir bilgisayar',
                    'Sistemin sınırlı sayıdaki durumdan birinde bulunduğu ve her girdiyle belirli kurallara göre durum değiştirdiği bir model',
                    'Bir döngünün en fazla kaç kez çalışacağını sınırlayan yapı',
                    'Sonsuz döngüleri durduran hata yakalama yöntemi',
                    $dontKnow,
                ],
                'b',
            ],
            [
                'Bir durum makinesinde **kabul durumu** ne anlama gelir?',
                [
                    'Makinenin hata verip durduğu durum',
                    'Kullanıcının programı kapatmayı onayladığı durum',
                    'Girdi bittiğinde makine bu durumlardan birindeyse girdinin geçerli sayıldığı durum',
                    'Makinenin her zaman başladığı ilk durum',
                    $dontKnow,
                ],
                'c',
            ],
            [
                'Bir metnin `14:05` gibi geçerli bir saat ifadesi olup olmadığını hazır fonksiyon kullanmadan, karakter karakter okuyarak kontrol edeceksiniz. Aşağıdakilerden hangisi **durum makinesi** yaklaşımını tarif eder?',
                [
                    'Metni `:` karakterinden bölüp iki parçayı `int()` ile sayıya çevirmek',
                    'Okunan kısmın hangi aşamada olduğunu bir değişkende tutmak ve her karakterde bu aşamaya göre bir sonraki aşamaya geçmek',
                    'Tüm karakterlerin rakam olup olmadığını tek tek kontrol etmek',
                    'Metni ters çevirip sondan başa doğru okumak',
                    $dontKnow,
                ],
                'b',
            ],
            [
                'Aşağıdakilerden hangisi tipik olarak bir **durum makinesiyle** modellenir?',
                [
                    'Bir listenin ortalamasını hesaplamak',
                    'İki sayının en büyük ortak bölenini bulmak',
                    'Bir video oynatıcının oynat, duraklat ve durdur düğmelerine göre davranması',
                    'Bir listeyi küçükten büyüğe sıralamak',
                    $dontKnow,
                ],
                'c',
            ],
        ];

        return array_map(fn (array $item, int $index) => [
            'session_number' => 0,
            'kind' => TaskKind::ConceptPrecheck,
            'title' => 'Kavram ön kontrolü '.($index + 1),
            'prompt' => $item[0],
            'mcq_options' => $this->options($item[1]),
            'mcq_answer' => $item[2],
            'max_score' => 1,
            'time_limit_seconds' => 60,
            'scoring_key' => [
                'measures' => 'concept_precheck',
                'expected_approach' => 'Kavramı önceden bilen katılımcı doğru seçeneği tanır; bilmeyen katılımcının "bilmiyorum" seçeneğini işaretlemesi beklenir.',
                'difficulty' => 'kolay',
                'estimated_minutes' => 1,
                'category_rationale' => 'Yalnızca kavramın önceden bilinip bilinmediğini ayırt eder; açıklama veya örnek içermediği için kavramı öğretmez. "Bilmiyorum" seçeneği tahmin etmeyi azaltır.',
            ],
        ], $items, array_keys($items));
    }

    /**
     * Session 1 practice and explanation tasks (no A/B form).
     *
     * @return list<array<string, mixed>>
     */
    private function sessionOneTasks(): array
    {
        return [
            [
                'session_number' => 1,
                'kind' => TaskKind::Learning,
                'title' => 'Ürün kodu doğrulama',
                'isomorph_group' => 'learn-kod',
                'prompt' => <<<'MD'
                    Bir mağazada ürün kodları şu kurala uymalıdır: **bir veya daha fazla büyük harf**, ardından **tek bir `-`**, ardından **bir veya daha fazla rakam**.

                    `gecerli_kod(metin)` fonksiyonunu yazın; kod kurala uyuyorsa `True`, uymuyorsa `False` döndürsün.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `"AB-12"` | `True` |
                    | `"X-7"` | `True` |
                    | `"ab-12"` | `False` |
                    | `"AB12"` | `False` |
                    | `"AB-"` | `False` |

                    Başlangıç kodundaki iskeleti kullanabilirsiniz. Önce hangi durumlara ihtiyaç olduğunu düşünün.
                    MD,
                'starter_code' => <<<'PY'
                    def gecerli_kod(metin):
                        durum = "BASLA"
                        for karakter in metin:
                            # Mevcut duruma ve karaktere göre yeni durumu belirleyin.
                            pass
                        # Girdi bittiğinde hangi durum "geçerli" anlamına gelir?
                        return False

                    PY,
                'reference_solution' => <<<'PY'
                    def gecerli_kod(metin):
                        durum = "BASLA"
                        for karakter in metin:
                            if durum == "BASLA":
                                durum = "HARF" if karakter.isupper() else "HATA"
                            elif durum == "HARF":
                                if karakter.isupper():
                                    durum = "HARF"
                                elif karakter == "-":
                                    durum = "TIRE"
                                else:
                                    durum = "HATA"
                            elif durum in ("TIRE", "RAKAM"):
                                durum = "RAKAM" if karakter.isdigit() else "HATA"
                        return durum == "RAKAM"

                    PY,
                'test_cases' => [
                    $this->test('gecerli_kod("AB-12")', 'True'),
                    $this->test('gecerli_kod("X-7")', 'True'),
                    $this->test('gecerli_kod("ab-12")', 'False'),
                    $this->test('gecerli_kod("AB12")', 'False'),
                    $this->test('gecerli_kod("AB-")', 'False'),
                    $this->test('gecerli_kod("-12")', 'False', true),
                    $this->test('gecerli_kod("AB-1-2")', 'False', true),
                    $this->test('gecerli_kod("")', 'False', true),
                    $this->test('gecerli_kod("ABC-0001")', 'True', true),
                ],
                'max_score' => 10,
                'min_reading_seconds' => 15,
                'planning_seconds' => 30,
                'time_limit_seconds' => 600,
                'help_allowed' => true,
                'scoring_key' => $this->codingKey(
                    measures: 'learning',
                    approach: 'BASLA, HARF, TIRE, RAKAM ve HATA durumlarıyla karakter karakter ilerlemek; sonda durumun RAKAM olup olmadığına bakmak.',
                    difficulty: 'kolay',
                    minutes: 8,
                    rationale: 'Öğrenme (pratik) görevi: ortak içerikteki örneğe yapıca çok yakındır ve kod iskeleti verilir; amaç kalıbı ilk kez uygulamaktır.',
                    alternatives: [
                        "import re\n\ndef gecerli_kod(metin):\n    return re.fullmatch(r\"[A-Z]+-[0-9]+\", metin) is not None",
                        "def gecerli_kod(metin):\n    if metin.count(\"-\") != 1:\n        return False\n    harf, rakam = metin.split(\"-\")\n    return harf.isalpha() and harf.isupper() and rakam.isdigit()",
                    ],
                    partial: [
                        ['Durumlar (en az başlangıç, harf, tire, rakam) ayrı ayrı tanımlanmış', 3],
                        ['Geçişler doğru; beklenmeyen karakter hata durumuna götürüyor', 4],
                        ['Sonda yalnızca kabul durumu True döndürüyor', 3],
                    ],
                    misconceptions: [
                        ['kabul_kontrolu_yok', 'Girdi bittikten sonra son durum kontrol edilmiyor ("AB-" geçerli sayılıyor)'],
                        ['durum_guncellenmiyor', 'Durum değişkeni döngü içinde güncellenmiyor'],
                        ['hatadan_cikis', 'Hata durumundan sonraki karakterlerle tekrar geçerli duruma dönülüyor'],
                    ],
                    objectives: ['uygulama'],
                ),
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::Learning,
                'title' => 'Metindeki sayıların adedi',
                'isomorph_group' => 'learn-say',
                'prompt' => <<<'MD'
                    `sayi_adedi(metin)` fonksiyonunu yazın. Fonksiyon, metinde yan yana duran rakamlardan oluşan **sayıların kaç tane** olduğunu döndürsün. Yan yana rakamlar tek bir sayı sayılır.

                    | Girdi | Beklenen çıktı | Açıklama |
                    |---|---|---|
                    | `"a12 b3 45x"` | `3` | 12, 3 ve 45 |
                    | `"2024"` | `1` | |
                    | `"1a2b3"` | `3` | |
                    | `"abc"` | `0` | |
                    | `""` | `0` | |
                    MD,
                'starter_code' => <<<'PY'
                    def sayi_adedi(metin):
                        durum = "DISARIDA"
                        adet = 0
                        for karakter in metin:
                            pass
                        return adet

                    PY,
                'reference_solution' => <<<'PY'
                    def sayi_adedi(metin):
                        durum = "DISARIDA"
                        adet = 0
                        for karakter in metin:
                            if karakter.isdigit():
                                if durum == "DISARIDA":
                                    adet += 1
                                durum = "SAYIDA"
                            else:
                                durum = "DISARIDA"
                        return adet

                    PY,
                'test_cases' => [
                    $this->test('sayi_adedi("a12 b3 45x")', '3'),
                    $this->test('sayi_adedi("2024")', '1'),
                    $this->test('sayi_adedi("1a2b3")', '3'),
                    $this->test('sayi_adedi("abc")', '0'),
                    $this->test('sayi_adedi("")', '0'),
                    $this->test('sayi_adedi("  7  ")', '1', true),
                    $this->test('sayi_adedi("x99y100z")', '2', true),
                ],
                'max_score' => 10,
                'min_reading_seconds' => 15,
                'planning_seconds' => 30,
                'time_limit_seconds' => 600,
                'help_allowed' => true,
                'scoring_key' => $this->codingKey(
                    measures: 'learning',
                    approach: 'DISARIDA ve SAYIDA durumları; sayacı yalnızca DISARIDA → SAYIDA geçişinde artırmak.',
                    difficulty: 'kolay',
                    minutes: 7,
                    rationale: 'Öğrenme (pratik) görevi: ortak içerikteki kelime sayma örneğinin çıktı üreten makine fikrini başka bir karakter sınıfıyla uygular.',
                    alternatives: [
                        "import re\n\ndef sayi_adedi(metin):\n    return len(re.findall(r\"[0-9]+\", metin))",
                        "def sayi_adedi(metin):\n    adet = 0\n    onceki = \"\"\n    for k in metin:\n        if k.isdigit() and not onceki.isdigit():\n            adet += 1\n        onceki = k\n    return adet",
                    ],
                    partial: [
                        ['İki durum (sayı içinde / dışında) tutuluyor', 3],
                        ['Sayaç yalnızca sayıya girişte artıyor', 4],
                        ['Boş metin ve sayısız metin 0 veriyor', 3],
                    ],
                    misconceptions: [
                        ['her_rakamda_sayma', 'Sayaç her rakamda artırılıyor (rakam adedi sayılıyor)'],
                        ['gecis_yerinde_hata', 'Sayaç sayıdan çıkışta artırılıyor; metin sayıyla bitince eksik sayılıyor'],
                    ],
                    objectives: ['uygulama'],
                ),
            ],
            [
                'session_number' => 1,
                'kind' => TaskKind::Explanation,
                'title' => 'Durum makinesi yaklaşımının açıklaması',
                'isomorph_group' => 'explain-1',
                'prompt' => <<<'MD'
                    Bu oturumda çözdüğünüz görevleri düşünün. Kod yazmadan, durum makinesi yaklaşımını konuyu bilmeyen bir arkadaşınıza anlatır gibi açıklayın:

                    - Bir durum makinesi nasıl çalışır? Durum, geçiş ve kabul durumu ne demektir?
                    - Bir problemi çözerken hangi durumlara ihtiyaç olduğuna nasıl karar verirsiniz?
                    - Kurallara uymayan bir girdi geldiğinde ne olmalıdır?
                    - Bu yaklaşım metin dışında hangi tür problemlerde işe yarar?
                    MD,
                'max_score' => 12,
                'time_limit_seconds' => 420,
                'scoring_key' => $this->explanationKey(
                    'Görev, problem çözme tamamlandıktan sonra gelir ve kavramın çalışma mantığını kendi cümleleriyle açıklama becerisini ölçer.',
                    self::MODEL_ANSWER_SESSION_ONE,
                ),
            ],
        ];
    }

    /**
     * Near transfer, far transfer and debugging tasks in two isomorphic forms.
     * Each pair measures the same skill with different surface features.
     *
     * @return list<array<string, mixed>>
     */
    private function formTasks(): array
    {
        return [
            ...$this->nearTransfer(),
            ...$this->farTransferDevice(),
            ...$this->farTransferCommands(),
            ...$this->farTransferQuotedFields(),
            ...$this->debugging(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nearTransfer(): array
    {
        $key = fn (string $function, string $prefix) => $this->codingKey(
            measures: 'near_transfer',
            approach: "Başlangıç, {$prefix}, tam kısım, nokta, kesir kısmı ve hata durumları; kabul durumları tam kısım ve kesir kısmı.",
            difficulty: 'orta',
            minutes: 7,
            rationale: 'Yakın transfer: öğrenilen metin doğrulama kalıbının aynı türde (karakter karakter biçim kontrolü) ama daha fazla durum gerektiren bir probleme uygulanması. Problemin bir durum makinesiyle çözülebileceği açıktır; yeni olan durum sayısıdır.',
            alternatives: [
                $prefix === 'işaret'
                    ? "import re\n\ndef {$function}(metin):\n    return re.fullmatch(r\"[+-]?[0-9]+(\\.[0-9]+)?\", metin) is not None"
                    : "import re\n\ndef {$function}(metin):\n    return re.fullmatch(r\"v?[0-9]+(\\.[0-9]+)?\", metin) is not None",
            ],
            partial: [
                ['Başlangıçtaki isteğe bağlı karakter doğru ele alınmış', 2],
                ['Noktadan sonra en az bir rakam şartı doğru', 3],
                ['Tanımsız girdiler hata durumuna gidiyor', 2],
                ['Kabul durumları doğru (nokta ile biten metin geçersiz)', 3],
            ],
            misconceptions: [
                ['kabul_kontrolu_yok', 'Son durum kontrol edilmiyor; "1." veya tek başına ön ek geçerli sayılıyor'],
                ['donusturmeye_guvenme', $prefix === 'işaret' ? 'float() ile dönüştürmenin başarılı olmasına güveniliyor ("3." gibi girdiler yanlış kabul ediliyor)' : 'Noktadan bölüp parçaların sayı olmasına bakılıyor, ön ek veya boş parçalar gözden kaçıyor'],
                ['tek_nokta_sayilmiyor', 'Birden fazla noktaya izin veriliyor'],
            ],
            objectives: ['uygulama'],
        );

        return [
            [
                'form' => 'A',
                'kind' => TaskKind::NearTransfer,
                'title' => 'Ondalık sayı doğrulama',
                'isomorph_group' => 'near-1',
                'prompt' => <<<'MD'
                    `gecerli_ondalik(metin)` fonksiyonunu yazın. Metin aşağıdaki kurala uyuyorsa `True`, uymuyorsa `False` döndürsün:

                    - İsteğe bağlı olarak tek bir `+` veya `-` işaretiyle başlayabilir,
                    - ardından en az bir rakam gelir,
                    - isteğe bağlı olarak bir `.` ve ardından **en az bir rakam** gelebilir.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `"42"` | `True` |
                    | `"-3.25"` | `True` |
                    | `"+0.5"` | `True` |
                    | `"3."` | `False` |
                    | `".5"` | `False` |
                    | `"1.2.3"` | `False` |

                    `float()` veya düzenli ifade (regex) kullanmayın; metni karakter karakter işleyin.
                    MD,
                'starter_code' => "def gecerli_ondalik(metin):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def gecerli_ondalik(metin):
                        durum = "BASLA"
                        for k in metin:
                            if durum == "BASLA":
                                durum = "ISARET" if k in "+-" else ("TAM" if k.isdigit() else "HATA")
                            elif durum == "ISARET":
                                durum = "TAM" if k.isdigit() else "HATA"
                            elif durum == "TAM":
                                durum = "TAM" if k.isdigit() else ("NOKTA" if k == "." else "HATA")
                            elif durum in ("NOKTA", "KESIR"):
                                durum = "KESIR" if k.isdigit() else "HATA"
                        return durum in ("TAM", "KESIR")

                    PY,
                'test_cases' => [
                    $this->test('gecerli_ondalik("42")', 'True'),
                    $this->test('gecerli_ondalik("-3.25")', 'True'),
                    $this->test('gecerli_ondalik("+0.5")', 'True'),
                    $this->test('gecerli_ondalik("3.")', 'False'),
                    $this->test('gecerli_ondalik(".5")', 'False'),
                    $this->test('gecerli_ondalik("1.2.3")', 'False'),
                    $this->test('gecerli_ondalik("+")', 'False', true),
                    $this->test('gecerli_ondalik("")', 'False', true),
                    $this->test('gecerli_ondalik("--1")', 'False', true),
                    $this->test('gecerli_ondalik("12a")', 'False', true),
                    $this->test('gecerli_ondalik("-0")', 'True', true),
                    $this->test('gecerli_ondalik("7.0001")', 'True', true),
                ],
                ...$this->codingTiming(420, 30),
                'scoring_key' => $key('gecerli_ondalik', 'işaret'),
            ],
            [
                'form' => 'B',
                'kind' => TaskKind::NearTransfer,
                'title' => 'Sürüm etiketi doğrulama',
                'isomorph_group' => 'near-1',
                'prompt' => <<<'MD'
                    Bir yazılımın sürüm etiketleri şu kurala uymalıdır. `gecerli_surum(metin)` fonksiyonunu yazın; kurala uyuyorsa `True`, uymuyorsa `False` döndürsün:

                    - İsteğe bağlı olarak küçük `v` harfiyle başlayabilir (yalnızca bir tane),
                    - ardından en az bir rakam gelir,
                    - isteğe bağlı olarak bir `.` ve ardından **en az bir rakam** gelebilir.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `"2"` | `True` |
                    | `"v1.5"` | `True` |
                    | `"v10.25"` | `True` |
                    | `"1."` | `False` |
                    | `".5"` | `False` |
                    | `"1.2.3"` | `False` |

                    Düzenli ifade (regex) veya `split()` kullanmayın; metni karakter karakter işleyin.
                    MD,
                'starter_code' => "def gecerli_surum(metin):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def gecerli_surum(metin):
                        durum = "BASLA"
                        for k in metin:
                            if durum == "BASLA":
                                durum = "ONEK" if k == "v" else ("ANA" if k.isdigit() else "HATA")
                            elif durum == "ONEK":
                                durum = "ANA" if k.isdigit() else "HATA"
                            elif durum == "ANA":
                                durum = "ANA" if k.isdigit() else ("NOKTA" if k == "." else "HATA")
                            elif durum in ("NOKTA", "ALT"):
                                durum = "ALT" if k.isdigit() else "HATA"
                        return durum in ("ANA", "ALT")

                    PY,
                'test_cases' => [
                    $this->test('gecerli_surum("2")', 'True'),
                    $this->test('gecerli_surum("v1.5")', 'True'),
                    $this->test('gecerli_surum("v10.25")', 'True'),
                    $this->test('gecerli_surum("1.")', 'False'),
                    $this->test('gecerli_surum(".5")', 'False'),
                    $this->test('gecerli_surum("1.2.3")', 'False'),
                    $this->test('gecerli_surum("v")', 'False', true),
                    $this->test('gecerli_surum("")', 'False', true),
                    $this->test('gecerli_surum("vv1")', 'False', true),
                    $this->test('gecerli_surum("1a")', 'False', true),
                    $this->test('gecerli_surum("v0")', 'True', true),
                    $this->test('gecerli_surum("3.0001")', 'True', true),
                ],
                ...$this->codingTiming(420, 30),
                'scoring_key' => $key('gecerli_surum', 'ön ek'),
            ],
        ];
    }

    /**
     * Far transfer 1: an event-driven device with three states. Nothing in
     * the story mentions text or states; recognising that the history can be
     * summarised by a state is the transfer.
     *
     * @return list<array<string, mixed>>
     */
    private function farTransferDevice(): array
    {
        $key = fn (string $open, string $use, string $service) => $this->codingKey(
            measures: 'far_transfer',
            approach: "KAPALI, ACIK ve {$service} modlarını üç durum olarak ele alıp her olayda duruma göre geçiş yapmak; sayaçları geçişlerde güncellemek.",
            difficulty: 'orta',
            minutes: 8,
            rationale: "Uzak transfer: girdi metin değil bir olay listesidir ve problemde \"durum\" kelimesi geçmez. Katılımcının geçmişi bir durumla özetleyebileceğini kendisinin fark etmesi gerekir. Üçüncü mod ({$service}) yalnızca iki değerli bir bayrakla çözmeyi zorlaştırır.",
            alternatives: [
                "Durumları True/False bayrakları ile tutan çözüm (acik ve {$service} için iki ayrı bayrak), geçiş kuralları aynıysa doğrudur.",
                'Geçişleri {(durum, olay): yeni_durum} sözlüğüyle tanımlayan tablo tabanlı çözüm.',
            ],
            partial: [
                ['Üç durum (kapalı, açık, özel mod) ayrı ayrı ele alınmış', 3],
                ["\"{$open}\" ve \"{$use}\" olaylarının normal moddaki geçişleri doğru", 3],
                ['Özel moda giriş ve moddan KAPALI duruma çıkış doğru', 2],
                ['Sayaçlar doğru geçişlerde artıyor ve ikili (tuple) döndürülüyor', 2],
            ],
            misconceptions: [
                ['moddan_aciga_donme', 'Özel moddan çıkınca açık duruma dönülüyor (kapalıya dönmeli)'],
                ['modda_kart_etkili', 'Özel moddayken diğer olaylar durumu değiştiriyor'],
                ['tek_bayrak', 'Yalnızca açık/kapalı bilgisi tutuluyor, özel mod gözden kaçıyor'],
                ['gecmisi_saymak', 'Durum yerine olayların sayısına bakılarak karar veriliyor'],
            ],
            objectives: ['tanima', 'genelleme'],
        );

        $tests = fn (string $function, array $map) => collect([
            [[], '(0, 0)', false],
            [['A', 'U'], '(1, 0)', false],
            [['U'], '(0, 1)', false],
            [['A', 'A', 'U', 'U'], '(1, 1)', false],
            [['S', 'U', 'U', 'S', 'U'], '(2, 1)', false],
            [['A', 'S', 'U', 'S', 'U'], '(1, 1)', true],
            [['U', 'A', 'S', 'A', 'U', 'S', 'A', 'U'], '(2, 1)', true],
        ])->map(fn (array $case) => $this->test(
            $function.'(['.implode(', ', array_map(fn ($event) => '"'.$map[$event].'"', $case[0])).'])',
            $case[1],
            $case[2],
        ))->all();

        return [
            [
                'form' => 'A',
                'kind' => TaskKind::FarTransfer,
                'title' => 'Otopark bariyeri',
                'isomorph_group' => 'far-1',
                'prompt' => <<<'MD'
                    Bir otopark bariyeri gün boyunca gelen olayları kaydediyor. Bariyer gün başında **kapalıdır**. Olaylar şunlardır:

                    - `"kart"`: Bariyer kapalıysa açılır. Açıksa bir şey değişmez.
                    - `"arac"`: Bariyer açıksa araç geçer ve bariyer hemen kapanır. Bariyer kapalıysa araç geçemez; bu bir **ihlal** olarak kaydedilir.
                    - `"bakim"`: Görevli bakım modunu açar. Bakım modunda bariyer sürekli açık kalır: gelen her araç geçer, `"kart"` olayı hiçbir şeyi değiştirmez. Bakım modundayken tekrar `"bakim"` gelirse bakım modu biter ve bariyer **kapalı** duruma döner.

                    `bariyer(olaylar)` fonksiyonunu yazın. Fonksiyon `(gecen_arac_sayisi, ihlal_sayisi)` ikilisini döndürsün.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `["kart", "arac"]` | `(1, 0)` |
                    | `["arac"]` | `(0, 1)` |
                    | `["kart", "kart", "arac", "arac"]` | `(1, 1)` |
                    | `["bakim", "arac", "arac", "bakim", "arac"]` | `(2, 1)` |
                    MD,
                'starter_code' => "def bariyer(olaylar):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def bariyer(olaylar):
                        durum = "KAPALI"
                        gecen = 0
                        ihlal = 0
                        for olay in olaylar:
                            if olay == "bakim":
                                durum = "KAPALI" if durum == "BAKIM" else "BAKIM"
                            elif durum == "BAKIM":
                                if olay == "arac":
                                    gecen += 1
                            elif olay == "kart":
                                durum = "ACIK"
                            elif olay == "arac":
                                if durum == "ACIK":
                                    gecen += 1
                                    durum = "KAPALI"
                                else:
                                    ihlal += 1
                        return (gecen, ihlal)

                    PY,
                'test_cases' => $tests('bariyer', ['A' => 'kart', 'U' => 'arac', 'S' => 'bakim']),
                ...$this->codingTiming(480, 45),
                'scoring_key' => $key('kart', 'arac', 'BAKIM'),
            ],
            [
                'form' => 'B',
                'kind' => TaskKind::FarTransfer,
                'title' => 'Akıllı kapı kilidi',
                'isomorph_group' => 'far-1',
                'prompt' => <<<'MD'
                    Bir binanın akıllı kapı kilidi gün boyunca gelen olayları kaydediyor. Kapı gün başında **kilitlidir**. Olaylar şunlardır:

                    - `"sifre"`: Kapı kilitliyse kilit açılır. Kilit zaten açıksa bir şey değişmez.
                    - `"giris"`: Kilit açıksa bir kişi girer ve kapı hemen yeniden kilitlenir. Kapı kilitliyken giriş denemesi olursa **alarm** çalar.
                    - `"servis"`: Teknisyen servis modunu açar. Servis modunda kapı serbesttir: her giriş denemesinde kişi içeri girer, `"sifre"` olayı hiçbir şeyi değiştirmez. Servis modundayken tekrar `"servis"` gelirse servis modu biter ve kapı **kilitli** duruma döner.

                    `kapi(olaylar)` fonksiyonunu yazın. Fonksiyon `(giris_sayisi, alarm_sayisi)` ikilisini döndürsün.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `["sifre", "giris"]` | `(1, 0)` |
                    | `["giris"]` | `(0, 1)` |
                    | `["sifre", "sifre", "giris", "giris"]` | `(1, 1)` |
                    | `["servis", "giris", "giris", "servis", "giris"]` | `(2, 1)` |
                    MD,
                'starter_code' => "def kapi(olaylar):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def kapi(olaylar):
                        durum = "KILITLI"
                        giris = 0
                        alarm = 0
                        for olay in olaylar:
                            if olay == "servis":
                                durum = "KILITLI" if durum == "SERVIS" else "SERVIS"
                            elif durum == "SERVIS":
                                if olay == "giris":
                                    giris += 1
                            elif olay == "sifre":
                                durum = "ACIK"
                            elif olay == "giris":
                                if durum == "ACIK":
                                    giris += 1
                                    durum = "KILITLI"
                                else:
                                    alarm += 1
                        return (giris, alarm)

                    PY,
                'test_cases' => $tests('kapi', ['A' => 'sifre', 'U' => 'giris', 'S' => 'servis']),
                ...$this->codingTiming(480, 45),
                'scoring_key' => $key('sifre', 'giris', 'SERVIS'),
            ],
        ];
    }

    /**
     * Far transfer 2: a four-state appliance where commands are only valid in
     * some states. Counting invalid commands requires an explicit state.
     *
     * @return list<array<string, mixed>>
     */
    private function farTransferCommands(): array
    {
        $key = fn (string $machine) => $this->codingKey(
            measures: 'far_transfer',
            approach: "{$machine} için dört durum tanımlamak; her komutun yalnızca belirli bir durumda geçerli olduğunu bir geçiş tablosu veya if/elif zinciriyle ifade etmek; geçersiz komutta durumu değiştirmeden sayacı artırmak.",
            difficulty: 'orta',
            minutes: 8,
            rationale: 'Uzak transfer: problem bir cihaz kullanım senaryosudur; durum makinesi kelimesi geçmez. Katılımcının "komutun geçerliliği önceki komutlara bağlı" olduğunu görüp bunu durumlarla modellemesi gerekir.',
            alternatives: [
                'Geçişleri {(durum, komut): yeni_durum} sözlüğüyle tanımlayıp sözlükte olmayan çiftleri geçersiz sayan çözüm.',
            ],
            partial: [
                ['Dört durum doğru tanımlanmış', 3],
                ['Geçerli geçişlerin tamamı doğru', 3],
                ['Geçersiz komutta durum değişmiyor', 2],
                ['Bilinmeyen komutlar da geçersiz sayılıyor', 2],
            ],
            misconceptions: [
                ['gecersizde_durum_degisiyor', 'Geçersiz komut da durumu değiştiriyor'],
                ['eksik_gecis', 'Bir durumdan çıkış yolu (ör. işlem bittikten sonra kapağı açma) tanımlanmamış'],
                ['komut_sirasina_bakma', 'Durum yerine yalnızca bir önceki komuta bakılıyor'],
            ],
            objectives: ['tanima', 'genelleme'],
        );

        $tests = fn (string $function, array $map) => collect([
            [[], '0', false],
            [['K', 'B', 'T', 'A'], '0', false],
            [['B'], '1', false],
            [['K', 'B', 'A', 'T', 'A'], '1', false],
            [['A', 'K', 'K'], '2', false],
            [['K', 'A', 'K', 'B', 'B', 'T', 'T', 'K'], '3', true],
            [['K', 'X', 'B', 'T', 'A', 'K'], '1', true],
        ])->map(fn (array $case) => $this->test(
            $function.'(['.implode(', ', array_map(fn ($command) => '"'.$map[$command].'"', $case[0])).'])',
            $case[1],
            $case[2],
        ))->all();

        return [
            [
                'form' => 'A',
                'kind' => TaskKind::FarTransfer,
                'title' => 'Çamaşır makinesi komutları',
                'isomorph_group' => 'far-2',
                'prompt' => <<<'MD'
                    Bir çamaşır makinesinin kontrol yazılımı kullanıcının bastığı tuşları sırayla alıyor. Makine başlangıçta **kapağı açık ve boş** bekliyor. Kurallar:

                    - `"kapat"`: Yalnızca kapak açıkken geçerlidir; kapak kapanır ve makine **hazır** olur.
                    - `"baslat"`: Yalnızca makine hazırken geçerlidir; yıkama başlar.
                    - `"bitti"`: Yalnızca yıkama sürerken geçerlidir; yıkama biter.
                    - `"ac"`: Makine hazırken ya da yıkama bittikten sonra geçerlidir; kapak açılır. Yıkama sürerken kapak açılamaz.

                    Bunların dışındaki her tuş basışı (bilinmeyen tuşlar dahil) **geçersizdir**: makine onu yok sayar ve hiçbir şey değişmez.

                    `gecersiz_komut_sayisi(komutlar)` fonksiyonunu yazın. Fonksiyon yok sayılan komutların sayısını döndürsün.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `["kapat", "baslat", "bitti", "ac"]` | `0` |
                    | `["baslat"]` | `1` |
                    | `["kapat", "baslat", "ac", "bitti", "ac"]` | `1` |
                    | `["ac", "kapat", "kapat"]` | `2` |
                    MD,
                'starter_code' => "def gecersiz_komut_sayisi(komutlar):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def gecersiz_komut_sayisi(komutlar):
                        gecisler = {
                            ("ACIK", "kapat"): "HAZIR",
                            ("HAZIR", "baslat"): "YIKIYOR",
                            ("YIKIYOR", "bitti"): "BITTI",
                            ("HAZIR", "ac"): "ACIK",
                            ("BITTI", "ac"): "ACIK",
                        }
                        durum = "ACIK"
                        gecersiz = 0
                        for komut in komutlar:
                            if (durum, komut) in gecisler:
                                durum = gecisler[(durum, komut)]
                            else:
                                gecersiz += 1
                        return gecersiz

                    PY,
                'test_cases' => $tests('gecersiz_komut_sayisi', ['K' => 'kapat', 'B' => 'baslat', 'T' => 'bitti', 'A' => 'ac', 'X' => 'sikma']),
                ...$this->codingTiming(480, 45),
                'scoring_key' => $key('Çamaşır makinesi (açık, hazır, yıkıyor, bitti)'),
            ],
            [
                'form' => 'B',
                'kind' => TaskKind::FarTransfer,
                'title' => 'Mikrodalga fırın tuşları',
                'isomorph_group' => 'far-2',
                'prompt' => <<<'MD'
                    Bir mikrodalga fırının kontrol yazılımı kullanıcının bastığı tuşları sırayla alıyor. Fırın başlangıçta **kapısı açık** bekliyor. Kurallar:

                    - `"kapat"`: Yalnızca kapı açıkken geçerlidir; kapı kapanır ve fırın **hazır** olur.
                    - `"baslat"`: Yalnızca fırın hazırken geçerlidir; ısıtma başlar.
                    - `"zil"`: Yalnızca ısıtma sürerken geçerlidir; ısıtma tamamlanır.
                    - `"ac"`: Fırın hazırken ya da ısıtma tamamlandıktan sonra geçerlidir; kapı açılır. Isıtma sürerken kapı açılamaz.

                    Bunların dışındaki her tuş basışı (bilinmeyen tuşlar dahil) **geçersizdir**: fırın onu yok sayar ve hiçbir şey değişmez.

                    `hatali_tus_sayisi(tuslar)` fonksiyonunu yazın. Fonksiyon yok sayılan tuş basışlarının sayısını döndürsün.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `["kapat", "baslat", "zil", "ac"]` | `0` |
                    | `["baslat"]` | `1` |
                    | `["kapat", "baslat", "ac", "zil", "ac"]` | `1` |
                    | `["ac", "kapat", "kapat"]` | `2` |
                    MD,
                'starter_code' => "def hatali_tus_sayisi(tuslar):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def hatali_tus_sayisi(tuslar):
                        gecisler = {
                            ("ACIK", "kapat"): "HAZIR",
                            ("HAZIR", "baslat"): "ISITIYOR",
                            ("ISITIYOR", "zil"): "TAMAM",
                            ("HAZIR", "ac"): "ACIK",
                            ("TAMAM", "ac"): "ACIK",
                        }
                        durum = "ACIK"
                        hatali = 0
                        for tus in tuslar:
                            if (durum, tus) in gecisler:
                                durum = gecisler[(durum, tus)]
                            else:
                                hatali += 1
                        return hatali

                    PY,
                'test_cases' => $tests('hatali_tus_sayisi', ['K' => 'kapat', 'B' => 'baslat', 'T' => 'zil', 'A' => 'ac', 'X' => 'buz_coz']),
                ...$this->codingTiming(480, 45),
                'scoring_key' => $key('Mikrodalga fırın (açık, hazır, ısıtıyor, tamam)'),
            ],
        ];
    }

    /**
     * Far transfer 3: splitting fields where a separator inside quotes does
     * not count. A plain split() fails; the insight is an "inside quotes"
     * state.
     *
     * @return list<array<string, mixed>>
     */
    private function farTransferQuotedFields(): array
    {
        $key = fn (string $separator, string $quote) => $this->codingKey(
            measures: 'far_transfer',
            approach: "NORMAL ve TIRNAK_ICINDE durumları; NORMAL durumda `{$separator}` alanı bitirir, `{$quote}` TIRNAK_ICINDE durumuna geçirir; TIRNAK_ICINDE durumunda ayırıcı alana eklenir, `{$quote}` NORMAL duruma döndürür; sonda son alan eklenir.",
            difficulty: 'zor',
            minutes: 9,
            rationale: 'Uzak transfer: görev bir metin ayırma problemi gibi görünür ve akla ilk gelen split() çözümü yanlıştır. Katılımcının ayırıcının anlamının "tırnak içinde miyim" bilgisine bağlı olduğunu fark edip bunu bir durumla modellemesi gerekir; doğrulama görevlerinden farklı olarak makine çıktı (alan listesi) üretir.',
            alternatives: [
                'Tırnak içinde olup olmadığını bir bool değişkenle (ör. tirnakta = not tirnakta) tutan çözüm aynı durum makinesinin iki durumlu hâlidir ve doğrudur.',
            ],
            partial: [
                ['Tırnak içi / dışı ayrımı bir durumla tutuluyor', 3],
                ['Tırnak dışındaki ayırıcı alanı bitiriyor, tırnak içindeki alana ekleniyor', 3],
                ['Tırnak karakterleri sonuca eklenmiyor', 2],
                ['Boş alanlar ve son alan doğru ekleniyor', 2],
            ],
            misconceptions: [
                ['split_kullanimi', "Metin doğrudan `{$separator}` ile bölünüyor; tırnak içindeki ayırıcılar yanlış bölünüyor"],
                ['son_alan_eklenmiyor', 'Döngü bitince son alan listeye eklenmiyor'],
                ['bos_alan_atlaniyor', 'Art arda iki ayırıcı arasındaki boş alan atlanıyor'],
                ['tirnak_ciktida', 'Tırnak karakterleri alan içeriğine ekleniyor'],
            ],
            objectives: ['tanima', 'genelleme'],
        );

        $tests = fn (string $function, string $separator, string $quote) => collect([
            ['a{s}b{s}c', "['a', 'b', 'c']", false],
            ['ad{s}{q}Ankara{s} TR{q}{s}34', "['ad', 'Ankara{s} TR', '34']", false],
            ['x{s}{s}y', "['x', '', 'y']", false],
            ['{q}a{s}b{q}', "['a{s}b']", false],
            ['', "['']", false],
            ['{q}1{s}2{q}{s}3{s}{q}4{s}5{s}6{q}', "['1{s}2', '3', '4{s}5{s}6']", true],
            ['tek', "['tek']", true],
            ['{s}', "['', '']", true],
        ])->map(function (array $case) use ($function, $separator, $quote) {
            $fill = fn (string $text) => str_replace(['{s}', '{q}'], [$separator, $quote], $text);
            $argument = $quote === '"' ? "'".$fill($case[0])."'" : '"'.$fill($case[0]).'"';

            return $this->test("{$function}({$argument})", $fill($case[1]), $case[2]);
        })->all();

        return [
            [
                'form' => 'A',
                'kind' => TaskKind::FarTransfer,
                'title' => 'Tırnaklı CSV satırını ayırma',
                'isomorph_group' => 'far-3',
                'prompt' => <<<'MD'
                    Bir CSV dosyasında alanlar virgülle (`,`) ayrılır. Ancak bir alan çift tırnak (`"`) içine alınmışsa, tırnak içindeki virgüller alanın parçasıdır ve ayırıcı sayılmaz. Tırnak karakterlerinin kendisi alana dahil değildir.

                    `alanlara_ayir(satir)` fonksiyonunu yazın; satırdaki alanların listesini döndürsün.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `'a,b,c'` | `['a', 'b', 'c']` |
                    | `'ad,"Ankara, TR",34'` | `['ad', 'Ankara, TR', '34']` |
                    | `'x,,y'` | `['x', '', 'y']` |
                    | `'"a,b"'` | `['a,b']` |
                    | `''` | `['']` |

                    `csv` modülünü veya düzenli ifade (regex) kullanmayın.
                    MD,
                'starter_code' => "def alanlara_ayir(satir):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def alanlara_ayir(satir):
                        alanlar = []
                        alan = ""
                        durum = "NORMAL"
                        for k in satir:
                            if durum == "NORMAL":
                                if k == ",":
                                    alanlar.append(alan)
                                    alan = ""
                                elif k == '"':
                                    durum = "TIRNAK"
                                else:
                                    alan += k
                            else:
                                if k == '"':
                                    durum = "NORMAL"
                                else:
                                    alan += k
                        alanlar.append(alan)
                        return alanlar

                    PY,
                'test_cases' => $tests('alanlara_ayir', ',', '"'),
                ...$this->codingTiming(540, 45),
                'scoring_key' => $key(',', '"'),
            ],
            [
                'form' => 'B',
                'kind' => TaskKind::FarTransfer,
                'title' => 'Tırnaklı etiket listesini ayırma',
                'isomorph_group' => 'far-3',
                'prompt' => <<<'MD'
                    Bir not uygulamasında etiketler noktalı virgülle (`;`) ayrılır. Ancak bir etiket tek tırnak (`'`) içine alınmışsa, tırnak içindeki noktalı virgüller etiketin parçasıdır ve ayırıcı sayılmaz. Tırnak karakterlerinin kendisi etikete dahil değildir.

                    `etiketlere_ayir(metin)` fonksiyonunu yazın; etiketlerin listesini döndürsün.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `"a;b;c"` | `['a', 'b', 'c']` |
                    | `"ad;'Ankara; TR';34"` | `['ad', 'Ankara; TR', '34']` |
                    | `"x;;y"` | `['x', '', 'y']` |
                    | `"'a;b'"` | `['a;b']` |
                    | `""` | `['']` |

                    Düzenli ifade (regex) kullanmayın.
                    MD,
                'starter_code' => "def etiketlere_ayir(metin):\n    pass\n",
                'reference_solution' => <<<'PY'
                    def etiketlere_ayir(metin):
                        etiketler = []
                        etiket = ""
                        durum = "NORMAL"
                        for k in metin:
                            if durum == "NORMAL":
                                if k == ";":
                                    etiketler.append(etiket)
                                    etiket = ""
                                elif k == "'":
                                    durum = "TIRNAK"
                                else:
                                    etiket += k
                            else:
                                if k == "'":
                                    durum = "NORMAL"
                                else:
                                    etiket += k
                        etiketler.append(etiket)
                        return etiketler

                    PY,
                'test_cases' => $tests('etiketlere_ayir', ';', "'"),
                ...$this->codingTiming(540, 45),
                'scoring_key' => $key(';', "'"),
            ],
        ];
    }

    /**
     * Debugging: a text filter with two planted state-machine bugs.
     *
     * @return list<array<string, mixed>>
     */
    private function debugging(): array
    {
        $key = fn (array $bugs) => $this->codingKey(
            measures: 'debugging',
            approach: 'Kodu bir örnek girdiyle durum durum izleyip hangi geçişin yanlış olduğunu bulmak; iki hatayı da düzeltmek.',
            difficulty: 'orta',
            minutes: 7,
            rationale: 'Hata bulma: durum makinesinin hatalı kullanımını (yanlış geçiş, yanlış çıktı üretimi) fark etmeyi ölçer. Kod sıfırdan yazılmaz; hatalar kavrama özgüdür.',
            alternatives: [
                'Kodu baştan yazan ama aynı davranışı veren çözüm; puanlamada bulunan hatalar ayrıca işaretlenir.',
            ],
            partial: [
                ['Durumdan çıkış geçişi düzeltilmiş', 5],
                ['Çıktıya eklenen/eklenmeyen karakter düzeltilmiş', 5],
            ],
            misconceptions: [
                ['belirti_yamasi', 'Hatanın kaynağı yerine belirtisi düzeltiliyor (ör. sonuçtan karakter silme)'],
                ['durumu_izlememe', 'Yalnızca bir hata düzeltilip diğer geçiş kontrol edilmiyor'],
            ],
            objectives: ['hata_fark'],
            bugs: $bugs,
        );

        return [
            [
                'form' => 'A',
                'kind' => TaskKind::Debugging,
                'title' => 'Etiket temizleyicideki hatalar',
                'isomorph_group' => 'debug-1',
                'prompt' => <<<'MD'
                    `etiketsiz(metin)` fonksiyonu bir metindeki `<...>` biçimindeki etiketleri silip yalnızca düz metni bırakmalıdır. Örneğin `"<b>Merhaba</b> dunya"` için sonuç `"Merhaba dunya"` olmalıdır.

                    Başlangıç kodundaki fonksiyonda **iki hata** var. Hataları bulup düzeltin.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `"<b>Merhaba</b> dunya"` | `"Merhaba dunya"` |
                    | `"a<i>b</i>c"` | `"abc"` |
                    | `"duz metin"` | `"duz metin"` |
                    | `"<br>"` | `""` |
                    MD,
                'starter_code' => <<<'PY'
                    def etiketsiz(metin):
                        sonuc = ""
                        durum = "METIN"
                        for k in metin:
                            if durum == "METIN":
                                if k == "<":
                                    durum = "ETIKET"
                                else:
                                    sonuc += k
                            elif durum == "ETIKET":
                                if k == ">":
                                    sonuc += k
                                    durum = "ETIKET"
                        return sonuc

                    PY,
                'reference_solution' => <<<'PY'
                    def etiketsiz(metin):
                        sonuc = ""
                        durum = "METIN"
                        for k in metin:
                            if durum == "METIN":
                                if k == "<":
                                    durum = "ETIKET"
                                else:
                                    sonuc += k
                            elif durum == "ETIKET":
                                if k == ">":
                                    durum = "METIN"
                        return sonuc

                    PY,
                'test_cases' => [
                    $this->test('etiketsiz("<b>Merhaba</b> dunya")', '"Merhaba dunya"'),
                    $this->test('etiketsiz("a<i>b</i>c")', '"abc"'),
                    $this->test('etiketsiz("duz metin")', '"duz metin"'),
                    $this->test('etiketsiz("<br>")', '""'),
                    $this->test('etiketsiz("")', '""', true),
                    $this->test('etiketsiz("<p><b>x</b></p>y")', '"xy"', true),
                ],
                ...$this->codingTiming(420, 30),
                'scoring_key' => $key([
                    ['line_hint' => 'ETIKET dalı, durum = "ETIKET"', 'description' => '`>` okununca durum METIN\'e dönmüyor; ilk etiketten sonraki tüm metin siliniyor.'],
                    ['line_hint' => 'ETIKET dalı, sonuc += k', 'description' => 'Kapanış karakteri `>` sonuca ekleniyor; etiket karakterleri çıktıya girmemeli.'],
                ]),
            ],
            [
                'form' => 'B',
                'kind' => TaskKind::Debugging,
                'title' => 'Yorum silicideki hatalar',
                'isomorph_group' => 'debug-1',
                'prompt' => <<<'MD'
                    `yorumsuz(kod)` fonksiyonu bir Python kodundaki yorumları silmelidir: `#` karakterinden satır sonuna kadar olan kısım silinir, ama **satır sonu karakteri (`\n`) korunur** ve sonraki satır normal şekilde devam eder.

                    Başlangıç kodundaki fonksiyonda **iki hata** var. Hataları bulup düzeltin.

                    | Girdi | Beklenen çıktı |
                    |---|---|
                    | `"x = 1 # not\ny = 2"` | `"x = 1 \ny = 2"` |
                    | `"a#b\nc#d\ne"` | `"a\nc\ne"` |
                    | `"kod"` | `"kod"` |
                    | `"# sadece yorum"` | `""` |
                    MD,
                'starter_code' => <<<'PY'
                    def yorumsuz(kod):
                        sonuc = ""
                        durum = "KOD"
                        for k in kod:
                            if durum == "KOD":
                                if k == "#":
                                    durum = "YORUM"
                                else:
                                    sonuc += k
                            elif durum == "YORUM":
                                if k == "\n":
                                    durum = "YORUM"
                        return sonuc

                    PY,
                'reference_solution' => <<<'PY'
                    def yorumsuz(kod):
                        sonuc = ""
                        durum = "KOD"
                        for k in kod:
                            if durum == "KOD":
                                if k == "#":
                                    durum = "YORUM"
                                else:
                                    sonuc += k
                            elif durum == "YORUM":
                                if k == "\n":
                                    sonuc += k
                                    durum = "KOD"
                        return sonuc

                    PY,
                'test_cases' => [
                    $this->test('yorumsuz("x = 1 # not\ny = 2")', '"x = 1 \ny = 2"'),
                    $this->test('yorumsuz("a#b\nc#d\ne")', '"a\nc\ne"'),
                    $this->test('yorumsuz("kod")', '"kod"'),
                    $this->test('yorumsuz("# sadece yorum")', '""'),
                    $this->test('yorumsuz("")', '""', true),
                    $this->test('yorumsuz("#\n#\n")', '"\n\n"', true),
                ],
                ...$this->codingTiming(420, 30),
                'scoring_key' => $key([
                    ['line_hint' => 'YORUM dalı, durum = "YORUM"', 'description' => 'Satır sonu okununca durum KOD\'a dönmüyor; ilk yorumdan sonraki tüm satırlar siliniyor.'],
                    ['line_hint' => 'YORUM dalı, satır sonu', 'description' => 'Satır sonu karakteri sonuca eklenmiyor; satırlar birleşiyor.'],
                ]),
            ],
        ];
    }

    /**
     * Session 2: delayed recall (separate from far transfer) and explanation.
     * The far transfer, near transfer and debugging items come from the form
     * the participant did not see in session 1.
     *
     * @return list<array<string, mixed>>
     */
    private function sessionTwoTasks(): array
    {
        $recall = [
            [
                'Bir durum makinesinde **bir sonraki durumu** ne belirler?',
                ['Yalnızca okunan girdi', 'Yalnızca mevcut durum', 'Mevcut durum ve okunan girdi', 'Şimdiye kadar okunan girdi sayısı'],
                'c',
            ],
            [
                'Bir metnin geçerli olup olmadığını kontrol eden bir durum makinesi, metnin **tamamını okuduktan sonra** ne yapmalıdır?',
                ['Hiçbir şey yapmamalı; hata yoksa metin geçerlidir', 'Son durumun kabul durumlarından biri olup olmadığını kontrol etmeli', 'Başlangıç durumuna dönmeli', 'Metni tersten bir kez daha okumalı'],
                'b',
            ],
            [
                'Bir durumdayken, o durum için hiçbir kural tanımlanmamış (beklenmeyen) bir girdi gelirse en uygun davranış hangisidir?',
                ['Girdiyi yok sayıp aynı durumda kalmak', 'Başlangıç durumuna dönüp okumaya devam etmek', 'Makineyi bir hata durumuna geçirip girdi bitene kadar orada tutmak', 'Bir önceki duruma geri dönmek'],
                'c',
            ],
        ];

        $recallTasks = array_map(fn (array $item, int $index) => [
            'session_number' => 2,
            'kind' => TaskKind::DelayedRecall,
            'title' => 'Hatırlama '.($index + 1),
            'isomorph_group' => 'recall-'.($index + 1),
            'prompt' => $item[0],
            'mcq_options' => $this->options($item[1]),
            'mcq_answer' => $item[2],
            'max_score' => 1,
            'time_limit_seconds' => 90,
            'scoring_key' => [
                'measures' => 'delayed_recall',
                'expected_approach' => 'Bir hafta önce öğrenilen kavramın temel kuralını hatırlamak.',
                'difficulty' => 'kolay',
                'estimated_minutes' => 1,
                'category_rationale' => 'Gecikmeli hatırlama: kavramın hatırlanıp hatırlanmadığını ölçer, yeni bir probleme uygulama gerektirmez. Uzak transfer görevlerinden ayrıdır ve hiçbir görevle izomorf grubu paylaşmaz.',
                'learning_objectives' => ['tanima', 'aciklama'],
            ],
        ], $recall, array_keys($recall));

        return [
            ...$recallTasks,
            [
                'session_number' => 2,
                'kind' => TaskKind::Explanation,
                'title' => 'Bir problem için durum makinesi tasarımının açıklaması',
                'isomorph_group' => 'explain-2',
                'prompt' => <<<'MD'
                    Bir trafik lambası **kırmızı → yeşil → sarı → kırmızı** sırasıyla değişiyor. Lamba bozulduğunda **yanıp sönen sarı** moda geçiyor ve görevli onu düzeltene kadar bu modda kalıyor; düzeltilince kırmızıdan başlıyor.

                    Kod yazmadan, bu lambayı bir durum makinesiyle nasıl modelleyeceğinizi açıklayın:

                    - Hangi durumlar olur? Başlangıç durumu hangisidir?
                    - Hangi olaylar hangi geçişlere yol açar?
                    - Beklenmeyen bir olay gelirse ne olmalıdır?
                    - Bu modelin, bir dizi olayı tek tek işleyen bir programda nasıl kullanılacağını anlatın.
                    MD,
                'max_score' => 12,
                'time_limit_seconds' => 420,
                'scoring_key' => $this->explanationKey('Görev, 2. oturumun sonunda, problem çözme görevlerinden sonra gelir. Kavramın yeni bir bağlamda açıklanmasını ölçer; uzak transfer kodlama görevlerinden ayrıdır.', self::MODEL_ANSWER_SESSION_TWO, [
                    'Durumların (kırmızı, yeşil, sarı, yanıp sönen sarı) ayrı ayrı belirtilmesi',
                    'Başlangıç durumunun ve bozulma/düzeltme geçişlerinin açıklanması',
                ]),
            ],
        ];
    }

    /**
     * Standard resource condition: reference material on the same core
     * concept, with examples that differ from every task in the battery.
     *
     * @return list<array<string, string>>
     */
    private function resourceArticles(): array
    {
        return [
            [
                'section' => 'concept',
                'title' => 'Durum makinesi nedir?',
                'body' => <<<'MD'
                    **Durum makinesi**, bir girdiyi (karakterler, komutlar, olaylar) baştan sona bir kez okuyan ve her an sınırlı sayıdaki **durumdan** birinde bulunan bir modeldir.

                    - **Durum:** Şu ana kadar okunanların özeti.
                    - **Geçiş:** "Bu durumdayken bu girdi gelirse yeni durum şudur" kuralı.
                    - **Başlangıç durumu:** Okumaya başlamadan önceki durum.
                    - **Kabul durumları:** Girdi bittiğinde bu durumlardan birindeysek sonuç geçerlidir.
                    - **Hata durumu:** Kurala uymayan girdide gidilen ve çıkılmayan durum.

                    Bir sonraki durumu yalnızca **mevcut durum** ve **okunan girdi** belirler.
                    MD,
            ],
            [
                'section' => 'concept',
                'title' => 'Durumları ve geçişleri tasarlamak',
                'body' => <<<'MD'
                    Kod yazmadan önce şu soruları yanıtlayın:

                    1. Girdiyi okurken hangi farklı aşamalarda olabilirim? Her aşama bir durumdur.
                    2. Hangi durumda başlarım? Hangi durumlarda bitersem sonuç geçerlidir?
                    3. Her durumda, her girdi türü için ne olur? Bunu bir **geçiş tablosu** olarak yazın: satırlar durumlar, sütunlar girdi türleri.
                    4. Tabloda boş kalan hücreler nereye gider? Genellikle bir hata durumuna.
                    5. Okurken bir şey sayılacak veya üretilecekse bunu **hangi geçişte** yapacağınızı belirleyin.
                    MD,
            ],
            [
                'section' => 'examples',
                'title' => 'Örnek: Basit e-posta biçimi',
                'body' => <<<'MD'
                    Kural: bir veya daha fazla harf, `@`, bir veya daha fazla harf, `.`, bir veya daha fazla harf (ör. `ali@okul.edu`).

                    ```python
                    def eposta_bicimi(metin):
                        durum = "KULLANICI_BAS"
                        for k in metin:
                            if durum in ("KULLANICI_BAS", "KULLANICI"):
                                if k.isalpha():
                                    durum = "KULLANICI"
                                elif k == "@" and durum == "KULLANICI":
                                    durum = "ALAN_BAS"
                                else:
                                    durum = "HATA"
                            elif durum in ("ALAN_BAS", "ALAN"):
                                if k.isalpha():
                                    durum = "ALAN"
                                elif k == "." and durum == "ALAN":
                                    durum = "UZANTI_BAS"
                                else:
                                    durum = "HATA"
                            elif durum in ("UZANTI_BAS", "UZANTI"):
                                durum = "UZANTI" if k.isalpha() else "HATA"
                        return durum == "UZANTI"
                    ```
                    MD,
            ],
            [
                'section' => 'examples',
                'title' => 'Örnek: Geçiş tablosunu sözlükle yazmak',
                'body' => <<<'MD'
                    Bir müzik çaların düğmeleri: `oynat`, `duraklat`, `durdur`. Geçişleri bir sözlükte tutmak kodu kısaltır:

                    ```python
                    gecisler = {
                        ("DURDU", "oynat"): "CALIYOR",
                        ("CALIYOR", "duraklat"): "DURAKLADI",
                        ("DURAKLADI", "oynat"): "CALIYOR",
                        ("CALIYOR", "durdur"): "DURDU",
                        ("DURAKLADI", "durdur"): "DURDU",
                    }

                    def son_durum(dugmeler):
                        durum = "DURDU"
                        for dugme in dugmeler:
                            durum = gecisler.get((durum, dugme), durum)
                        return durum
                    ```

                    `son_durum(["oynat", "duraklat", "oynat"])` → `"CALIYOR"`. Tabloda olmayan bir (durum, düğme) çifti durumu değiştirmez.
                    MD,
            ],
            [
                'section' => 'syntax',
                'title' => 'Metin ve sözlük işlemleri',
                'body' => <<<'MD'
                    | İfade | Anlamı |
                    |---|---|
                    | `for k in metin:` | Metni karakter karakter dolaşır |
                    | `k.isdigit()` | `k` bir rakamsa `True` |
                    | `k.isalpha()` | `k` bir harfse `True` |
                    | `k.isupper()` | `k` büyük harfse `True` |
                    | `k in "+-"` | `k`, `+` veya `-` ise `True` |
                    | `sozluk[(a, b)]` | Demet (tuple) anahtarla sözlük erişimi |
                    | `sozluk.get(anahtar, varsayilan)` | Anahtar yoksa varsayılanı verir |
                    | `return (x, y)` | İki değeri bir ikili olarak döndürür |
                    | `liste.append(x)` | Listeye eleman ekler |
                    MD,
            ],
            [
                'section' => 'common_errors',
                'title' => 'Sık yapılan hatalar',
                'body' => <<<'MD'
                    - **Durumu güncellememek:** Döngü içinde `durum = ...` atamasını unutmak.
                    - **Son durumu kontrol etmemek:** Girdi bittiğinde kabul durumunda olup olmadığına bakmamak; yarım kalmış girdiler geçerli sayılır.
                    - **Eksik geçişler:** Bir durumda bazı girdileri hiç düşünmemek.
                    - **Hata durumundan çıkmak:** Hatalı bir karakterden sonra tekrar geçerli bir duruma dönmek.
                    - **Yanlış geçişte saymak:** Sayacı her karakterde değil, yalnızca ilgili geçişte artırmak gerekir.
                    - **Son parçayı unutmak:** Okurken parça üreten makinelerde döngü bittikten sonra son parçayı eklemeyi unutmak.
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
                'description' => 'Durum, geçiş, başlangıç ve kabul durumu kavramlarını doğru kullanıyor mu?',
                'anchors' => ['0' => 'Yanlış veya ilgisiz', '1' => 'Kısmen doğru, önemli kavram hatası var', '2' => 'Doğru, küçük eksikler var', '3' => 'Tam ve doğru'],
            ],
            [
                'key' => 'solution_logic',
                'label' => 'Çözüm mantığını açıklama',
                'description' => 'Durumların nasıl belirlendiğini ve her girdide durumun nasıl değiştiğini açıklıyor mu?',
                'anchors' => ['0' => 'Açıklama yok', '1' => 'Yalnızca ne yaptığını söylüyor', '2' => 'Durumları ve geçişleri açıklıyor, sonucun nasıl belirlendiği belirsiz', '3' => 'Durumlar, geçişler ve sonucun son duruma göre belirlenmesi açık'],
            ],
            [
                'key' => 'generalization',
                'label' => 'Genellenebilirlik',
                'description' => 'Yaklaşımın metin dışındaki problemlere (komut, olay dizileri) nasıl uygulanacağını gösterebiliyor mu?',
                'anchors' => ['0' => 'Yok', '1' => 'Yüzeysel benzer bir örnek', '2' => 'Farklı bir bağlam doğru eşleştirilmiş', '3' => 'Genel ilkeyi (geçmişin bir durumla özetlenmesi) açıkça soyutlamış'],
            ],
            [
                'key' => 'edge_cases',
                'label' => 'Sınır durumlarını fark etme',
                'description' => 'Boş girdi, beklenmeyen girdi, hata durumu, yarım kalmış girdi gibi durumları fark ediyor mu?',
                'anchors' => ['0' => 'Hiç değinilmemiş', '1' => 'Belirsiz bir değinme', '2' => 'Bir sınır durumu doğru', '3' => 'Birden fazla sınır durumu ve etkisi doğru'],
            ],
        ];
    }

    /**
     * @param  list<string>  $texts
     * @return list<array{key: string, text: string}>
     */
    private function options(array $texts): array
    {
        return array_map(fn (string $text, int $index) => ['key' => chr(97 + $index), 'text' => $text], $texts, array_keys($texts));
    }

    /**
     * @return array{call: string, expected: string, hidden: bool}
     */
    private function test(string $call, string $expected, bool $hidden = false): array
    {
        return ['call' => $call, 'expected' => $expected, 'hidden' => $hidden];
    }

    /**
     * Form tasks share one timing scheme: help switch on (used only in
     * session 1), a reading minimum and a silent planning window.
     *
     * @return array<string, mixed>
     */
    private function codingTiming(int $timeLimitSeconds, int $planningSeconds): array
    {
        return [
            'max_score' => 10,
            'min_reading_seconds' => 15,
            'planning_seconds' => $planningSeconds,
            'time_limit_seconds' => $timeLimitSeconds,
            'help_allowed' => true,
        ];
    }

    /**
     * @param  list<string>  $alternatives
     * @param  list<array{0: string, 1: int|float}>  $partial
     * @param  list<array{0: string, 1: string}>  $misconceptions
     * @param  list<string>  $objectives
     * @param  list<array{line_hint: string, description: string}>  $bugs
     * @return array<string, mixed>
     */
    private function codingKey(
        string $measures,
        string $approach,
        string $difficulty,
        int $minutes,
        string $rationale,
        array $alternatives,
        array $partial,
        array $misconceptions,
        array $objectives,
        array $bugs = [],
    ): array {
        return [
            'measures' => $measures,
            'expected_approach' => $approach,
            'difficulty' => $difficulty,
            'estimated_minutes' => $minutes,
            'category_rationale' => $rationale,
            'alternative_solutions' => $alternatives,
            'partial_credit' => array_map(fn (array $item) => ['criterion' => $item[0], 'points' => $item[1]], $partial),
            'misconceptions' => array_map(fn (array $item) => ['code' => $item[0], 'description' => $item[1]], $misconceptions),
            'target_bugs' => $bugs,
            'explanation_elements' => [],
            'learning_objectives' => $objectives,
        ];
    }

    /**
     * @param  list<string>  $extraElements
     * @return array<string, mixed>
     */
    private function explanationKey(string $rationale, string $modelAnswer, array $extraElements = []): array
    {
        return [
            'measures' => 'explanation',
            'expected_approach' => "Kavramı kendi cümleleriyle, kod yazmadan açıklamak.\n\nÖrnek iyi açıklama (ilk cevap): {$modelAnswer}",
            'difficulty' => 'orta',
            'estimated_minutes' => 6,
            'category_rationale' => $rationale,
            'explanation_elements' => [
                'Durumun, şu ana kadar okunan girdinin özeti olduğu',
                'Bir sonraki durumu mevcut durum ve okunan girdinin belirlediği (geçiş kuralları)',
                'Başlangıç durumu ve sonucun son duruma (kabul durumu) göre belirlenmesi',
                'Beklenmeyen girdinin hata durumuna götürülmesi',
                'Yaklaşımın metin dışındaki olay/komut dizilerine de uygulanabilmesi',
                ...$extraElements,
            ],
            'learning_objectives' => ['aciklama'],
        ];
    }
}
