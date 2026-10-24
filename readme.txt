=== Tainacan OCR Search ===
Contributors: marcossigismundo
Tags: tainacan, ocr, pdf, tesseract, ocrmypdf, busca, fichas medicas
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Aplica OCR (Tesseract + OCRmyPDF) em PDFs e imagens das coleções Tainacan, deixando o conteúdo pesquisável pela busca textual nativa.

== Description ==

O Tainacan OCR Search é um plugin pensado para acervos com fichas médicas, relatórios escaneados e outros documentos em imagem. Ele:

* Verifica automaticamente se Tesseract e OCRmyPDF estão disponíveis no servidor.
* Reconhece o texto das páginas escaneadas e guarda em cada anexo, com quebra de página. Por padrão o arquivo original não é alterado; opcionalmente, o PDF é trocado pela versão com camada de texto (PDF/A).
* Processa anexos em lote, por coleção, com barra de progresso e log.
* Entrega o texto do documento principal ao índice de conteúdo do Tainacan (filtro `tainacan-index-pdf`), para a busca textual nativa; plugins como o Oráculo Tainacan leem também o texto dos demais anexos.
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

== Changelog ==

= 1.2.0 =
* Quadro "Texto dos arquivos (OCR)" na edição do item: situação de cada PDF e imagem do item (sem OCR, na fila, reconhecendo, com texto, falhou), botão "Aplicar OCR em N arquivo(s)", "Refazer", "Tentar de novo" e leitura do texto reconhecido página a página. O catalogador não precisa sair do item.
* OCR em fila de segundo plano (WP-Cron, um arquivo por vez, recupera processamento travado): nenhuma tela fica presa esperando o OCR. O lote da página admin enfileira e acompanha (pode fechar a página; "Pausar" tira da fila o que não começou), e o OCR automático no envio usa a mesma fila.
* REST: /item/{id} (situação), /item/{id}/ocr, /item/{id}/text/{anexo} — permissão de edição do item; /enqueue, /dequeue, /progress para o lote.

= 1.1.1 =
* Correção: contagem de páginas ignorava as páginas em branco do fim do documento (o texto e as quebras de página já estavam certos).

= 1.1.0 =
* Preservação: o PDF original não é mais sobrescrito por padrão; o texto do OCR fica no anexo (`_tainacan_ocr_text`, páginas separadas por quebra de página, e `_tainacan_ocr_pages`). A opção "Substituir o PDF original" mantém o comportamento antigo; imagens nunca geram PDF órfão ao lado.
* Integração com o core: o texto do documento principal entra no `document_content_index` pelo filtro `tainacan-index-pdf` (Tainacan 1.4), com a quebra de página trocada por linha em branco (o filtro de qualidade do core recusa caracteres de controle e apagaria o índice). Em cores anteriores e em documentos de imagem, o índice é gravado direto.
* Segurança: caminhos do Tesseract/OCRmyPDF só aceitam executáveis com esses nomes, em caminho absoluto existente; idioma validado no formato do Tesseract; argumentos REST com sanitize/validate.
* Fila: miniaturas geradas pelo Tainacan ficam de fora (exceto documento em imagem); OCR automático só para anexos de itens Tainacan.
* Sem disparar `tainacan-insert` nem `wp_update_post` depois do OCR (geravam logs e reprocessamento falsos); nova ação `tainacan_ocr_search_text_updated( $attachment_id, $item_id, $text )`.
* WP-CLI: `wp tainacan-ocr status|process|reindex`. Página admin mostra pdftotext/unpaper e liga a indexação de PDF do Tainacan, reindexando em segundo plano o que já passou por OCR.
* Correções: includes do wp-admin em REST/cron; `--clean` ignorado sem unpaper.

= 1.0.1 =
* Encoding da saída de comandos no Windows; guia de instalação.
