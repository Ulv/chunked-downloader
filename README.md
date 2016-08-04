# Класс для скачивания больших файлов по HTTP по частям с поддержкой SSL/HTTP auth

Фичи:
- удаленный файл скачивается по частям. Размер устанавливается в константе класса CHUNK_SIZE_MB
- есть возможность использовать SSL (скачивание файла по HTTPS)
- есть возможность использовать простую HTTP аутентификацию (basic HTTP authentication)

Пример использования:

```
$downloader = new ChunkedDownloader();
$downloader->setSourceFile($remoteUri);
$downloader->setDestinationFile($cacheFile);
$downloader->setUseSSL(true);
$downloader->setHttpAuth(config("learner.user"), config("learner.pass"));
$downloader->download();
```
