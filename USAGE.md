# 📖 Laravel Test Generator - Kullanım Rehberi

## 🚀 Hızlı Başlangıç

### 1️⃣ Packagist'e Yayınlama

✅ **GitHub'da v1.0.0 tag'i oluşturuldu!**

Şimdi https://packagist.org adresine git:
1. **"Submit"** butonuna tıkla
2. Repo URL'ini gir: `https://github.com/bberkaysari/laravel-test-generator`
3. **Submit** yap

Packagist otomatik olarak:
- GitHub releases'leri takip eder
- Her yeni tag'de otomatik günceller
- Composer'dan yüklenebilir hale getirir

### 2️⃣ Kullanıcılar Nasıl Yükler?

```bash
composer require --dev bberkaysari/laravel-test-generator
```

### 3️⃣ Kullanım Örnekleri

#### 📌 Komut Satırı (CLI)

```bash
# Tüm testleri oluştur
php vendor/bin/generate-tests

# Sadece model testleri
php vendor/bin/generate-tests --type=model

# Sadece controller testleri
php vendor/bin/generate-tests --type=controller

# Özel seçenekler
php vendor/bin/generate-tests \
  --path=/path/to/laravel \
  --output=tests/Generated \
  --force \
  --no-cache
```

#### 📌 PHP Kodu İçinde

```php
use Bberkaysari\LaravelTestGenerator\Core\ProjectAnalyzer;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ModelTestGenerator;

// Projeyi analiz et
$analyzer = new ProjectAnalyzer('/path/to/laravel-project');
$results = $analyzer->analyze();

// Model testleri oluştur
$generator = new ModelTestGenerator();
foreach ($results['models'] as $model) {
    $testCode = $generator->generate($model);
    file_put_contents(
        $generator->getTestPath($model['namespace'], $model['name']),
        $testCode
    );
}
```

---

## 🎯 Özellikler

### ✅ Otomatik Test Üretimi

**Model Testleri:**
- Fillable alanlar doğrulama
- Cast'ler (json, array, date, boolean)
- İlişkiler (hasMany, belongsTo, belongsToMany, hasOne)
- Scope'lar

**Controller Testleri:**
- HTTP metodları (GET, POST, PUT, DELETE)
- Validation rules
- Route parametreleri
- Middleware

**Service/Repository Testleri:**
- Constructor dependency injection
- Method mocking
- Return type assertions

### ⚡ Performans

- **İlk çalıştırma:** ~60ms (örnek proje)
- **Cache sonrası:** ~2ms (30x hızlanma)
- **1000+ model:** Enterprise-scale desteği
- **Paralel işleme:** Büyük projeler için optimize

---

## 📊 CI/CD Badge'leri

README'de şu badge'ler var:
- ✅ **CI Status:** GitHub Actions otomatik test
- ✅ **145 Tests Passing:** Tüm testler geçiyor
- ✅ **PHPStan Level 5:** Statik analiz
- ✅ **PHP 8.2|8.3|8.4:** Çoklu PHP desteği
- ✅ **MIT License:** Açık kaynak

---

## 🔥 Demo Çalıştırma

```bash
# Örnek proje ile demo
php bin/demo.php

# Kendi Laravel projenle
php bin/demo.php /path/to/your-laravel-project
```

**Demo Çıktısı:**
- Model analizi (fillable, casts, relations)
- Controller analizi (methods, validation)
- Migration parsing
- Test tahmini (kaç test oluşturulacak)
- Performans metrikleri

---

## 🌟 Başarı Hikayen

✅ 145 test, 394 assertion  
✅ 70%+ code coverage  
✅ PHP 8.2, 8.3, 8.4 desteği  
✅ Symfony v7/v8 uyumlu  
✅ GitHub Actions CI/CD  
✅ PHPStan level 5  
✅ Production-ready  

---

## 📦 Packagist'e Sonraki Adımlar

1. **Packagist Submit:** https://packagist.org/packages/submit
2. **GitHub Auto-Update:** Packagist webhook ekle (Settings → Webhooks)
3. **Composer Stats:** İndirme sayılarını takip et
4. **Versioning:** Semantic versioning (v1.0.0, v1.1.0, v2.0.0)

Yeni özellik ekledikçe:
```bash
git tag -a v1.1.0 -m "feat: new feature"
git push origin v1.1.0
```

Packagist otomatik güncellenir! 🚀
