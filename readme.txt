=== Tainacan OCR Search ===
Contributors: marcossigismundo
Tags: tainacan, ocr, pdf, tesseract, ocrmypdf, busca, fichas medicas
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Aplica OCR (Tesseract + OCRmyPDF) em PDFs e imagens das coleções Tainacan, deixando o conteúdo pesquisável pela busca textual nativa.

== Description ==

O Tainacan OCR Search é um plugin pensado para acervos com fichas médicas, relatórios escaneados e outros documentos em imagem. Ele:

* Verifica automaticamente se Tesseract e OCRmyPDF estão disponíveis no servidor.
* Adiciona ao Tesseract uma camada de texto invisível por baixo das páginas escaneadas (preserva o visual original).
* Processa anexos em lote, por coleção, com barra de progresso e log.
* Reindexa o item-pai no Tainacan para que o conteúdo apareça na busca textual.
* Pode rodar OCR automaticamente em novos uploads.
* 100% on-premises — nenhum documento sai do servidor (LGPD).

== Dependências ==

* [Tesseract OCR](https://github.com/tesseract-ocr/tesseract) com pacote de idioma `por`.
* [OCRmyPDF](https://github.com/ocrmypdf/OCRmyPDF).
* (Opcional) `pdftotext` (poppler-utils) para extração de texto puro.
* (Opcional) PHP Imagick para converter imagens isoladas em PDF.

Linux: `sudo apt install tesseract-ocr tesseract-ocr-por ocrmypdf poppler-utils`
macOS: `brew install tesseract tesseract-lang ocrmypdf poppler`
Windows: instale o Tesseract UB-Mannheim e `pip install ocrmypdf`.

== Uso ==

1. Ative o plugin (com Tainacan já ativo).
2. Vá em **Tainacan → OCR Search**.
3. Siga os 4 passos da página: verificar dependências, configurar, processar em lote, e (opcional) rodar `wp tainacan index-content --collection=all` para reindexar o acervo inteiro.
