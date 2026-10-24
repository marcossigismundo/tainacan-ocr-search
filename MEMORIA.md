# Memória do projeto (decisões, armadilhas, pendências)

> Para retomar o trabalho em outra máquina ou instância. Complementa o
> [CLAUDE_CONTEXT.md](CLAUDE_CONTEXT.md), que descreve o código. Atualizado em 24/10/2026 (versão 1.2.3).
> Endereços, credenciais e comandos de servidor ficam no repositório privado de infraestrutura,
> não aqui (este repositório é público).

## Papel do plugin no conjunto

O OCR do acervo fica **neste plugin** (Tesseract + OCRmyPDF, no próprio servidor, sem custo por
página e sem enviar documentos para fora). Quem lê o texto:

- o **núcleo do Tainacan**, pelo filtro `tainacan-index-pdf` (documento principal →
  `document_content_index` → busca textual nativa);
- o **Oráculo Tainacan** (busca com IA e BIA), que lê `_tainacan_ocr_text` de todos os arquivos do
  item e é avisado pela ação `tainacan_ocr_search_text_updated`;
- o **tainacan-ai** não faz OCR de escaneados (no modo visual só sugere metadados); um modo
  "transcrever" com modelo de visão seria PR no repositório oficial, opcional e cobrado por página.

## Linha do tempo das decisões

- **1.1.0 (23/10/2026), revisão.** O PDF original não é mais sobrescrito por padrão (opção
  "Substituir o PDF original"); o texto fica no anexo com `\f` entre páginas. Caminhos dos binários
  só aceitam `tesseract`/`ocrmypdf` em caminho absoluto existente; idioma validado. Fila exclui as
  miniaturas que o Tainacan gera. WP-CLI `wp tainacan-ocr status|process|reindex`.
- **1.2.x (24/10/2026), OCR dentro do item.** Pedido do usuário: o catalogador não deve sair da
  edição do item. Quadro "Texto dos arquivos (OCR)" no formulário do item (gancho
  `tainacan_register_admin_hook( 'item', …, 'begin-left' )`, JS com `wp.hooks`), com situação de
  cada arquivo, "Aplicar OCR", "Refazer" e leitura do texto por página. Todo OCR passou para uma
  fila no WP-Cron (`class-ocr-queue.php`); o OCR automático enfileira no próprio envio.

## Armadilhas que já custaram tempo

- **Quebra de página no índice do núcleo.** O filtro de qualidade do Tainacan 1.4
  (`Pdf_Extracted_Text_Quality`) recusa texto com caractere de controle e então **apaga** o
  `document_content_index`. Para o núcleo, `\f` vira linha em branco (`Processor::text_for_index`).
- **Reindex do núcleo pula arquivo que não mudou.** Com o original preservado, nome/tamanho/data
  não mudam; apague `document_content_last_index` antes de `generate_index_content()`.
- **`trim()` não remove `\f`.** Página em branco devolve só a quebra; trate como sem texto.
- **WP-Cron roda os eventos vencidos em sequência.** Um evento agendado atrás de workers lentos de
  outros plugins espera minutos; por isso o envio enfileira direto e o quadro chama `spawn_cron()`.
- **OCR é lento em servidor pequeno:** ~2–3 min por 16 páginas com 1 núcleo. Nunca rodar dentro
  de uma requisição de tela.
- **Não disparar `tainacan-insert` nem `wp_update_post` depois do OCR:** geram logs e
  reprocessamento falsos em outros plugins.

## Pendências

- O teste da tela de lote com milhares de arquivos ainda não foi feito (só itens e lote pequeno).
- O texto "em segundo plano" depende do WP-Cron; em site com `DISABLE_WP_CRON`, é preciso um cron
  do sistema chamando `wp cron event run --due-now`.
