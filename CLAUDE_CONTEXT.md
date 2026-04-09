# CLAUDE_CONTEXT — Tainacan OCR Search

> Arquivo de contexto para retomar o desenvolvimento deste plugin em qualquer sessão futura. Leia primeiro antes de modificar qualquer coisa.

## Visão geral

**Tainacan OCR Search** é um plugin WordPress que torna pesquisáveis, dentro da busca textual nativa do Tainacan, o conteúdo de PDFs e imagens escaneadas (fichas médicas, relatórios, documentos de arquivo).

Ele aplica OCR usando ferramentas livres (**Tesseract** + **OCRmyPDF**) sem alterar o visual original dos documentos, e reindexa o item-pai no Tainacan automaticamente.

- **Repositório**: https://github.com/marcossigismundo/tainacan-ocr-search
- **Path local (Windows/XAMPP)**: `C:\xampp82\htdocs\wordpress\wp-content\plugins\tainacan-ocr-search`
- **Versão atual**: 1.0.1
- **Autor**: Marcos Sigismundo
- **Idioma da interface**: Português brasileiro
- **Licença**: GPL-2.0-or-later

## Caso de uso original

Acervo de **fichas médicas e relatórios escaneados** que precisam ficar pesquisáveis dentro de coleções Tainacan, com:
- Tudo on-premises (LGPD / sigilo médico — nenhum documento sai do servidor).
- Software 100% livre.
- Preservação visual dos documentos originais (camada de texto invisível por baixo da imagem).
- Interface integrada e auto-explicativa para um operador não-técnico.

## Stack

| Camada | Software | Função |
|---|---|---|
| OCR engine | Tesseract OCR (Apache 2.0) | Reconhecimento de caracteres |
| OCR wrapper | OCRmyPDF (MPL 2.0) | Adiciona camada de texto a PDFs preservando layout |
| Suporte | Ghostscript, Python 3 | Dependências do OCRmyPDF |
| Extração de texto | pdftotext (poppler) | Fallback rápido para popular metadado |
| Fallback de extração | Smalot\PdfParser | Já vem com o Tainacan; usado se pdftotext faltar |
| Conversão imagem→PDF | PHP Imagick (opcional) | Para imagens soltas (jpg/png/tiff) |
| CMS | WordPress 6.0+ + Tainacan | Coleções, metadados, busca textual |
| Indexação | `wp tainacan index-content` | Reindexa o conteúdo dos PDFs no Tainacan |

## Estrutura do código

```
tainacan-ocr-search/
├── tainacan-ocr-search.php       # Bootstrap, hooks WP, ativação
├── readme.txt                    # Header WordPress.org
├── CLAUDE_CONTEXT.md             # Este arquivo
├── docs/
│   └── INSTALL.md                # Guia detalhado Linux/macOS/Windows
├── includes/
│   ├── class-ocr-admin-page.php  # Página admin (estende \Tainacan\Pages)
│   ├── class-ocr-processor.php   # Núcleo: chama Tesseract/OCRmyPDF
│   └── class-ocr-rest.php        # REST controller para a UI
└── assets/
    ├── admin.js                   # UI: progresso em lote via wp.apiFetch
    └── admin.css                  # Visual integrado ao Tainacan
```

## Fluxo principal

```
1. Operador abre Tainacan → OCR Search
2. Card 1: Verificar dependências → REST /check
3. Card 2: Configurações (caminhos, idioma, opções) → REST /settings
4. Card 3: Calcular fila de uma coleção → REST /queue
5. Card 3: Iniciar OCR → loop chamando REST /process por ID
6. Para cada anexo:
   a. Processor::process_attachment()
   b. Imagem → converte para PDF via Imagick
   c. ocrmypdf -l por --deskew --clean --skip-text
   d. PDF de saída sobrescreve original (PDFs) ou vira .ocr.pdf (imagens)
   e. Extrai texto via pdftotext (ou Smalot fallback)
   f. Salva em meta _tainacan_ocr_text e marca _tainacan_ocr_processed
   g. Re-salva o item-pai → dispara reindex do Tainacan
7. Card 4: instrui rodar `wp tainacan index-content --collection=all`
```

## Pontos críticos / armadilhas conhecidas

### 1. `\Tainacan\Pages` tem construtor `protected`
A classe pai define `protected function __construct()`, então **não dá para fazer `new Admin_Page()` no escopo global**. Solução: a subclasse implementa um singleton via `get_instance()` que mantém o construtor `protected` e expõe a criação. Ver [includes/class-ocr-admin-page.php](includes/class-ocr-admin-page.php).

### 2. `load_page()` precisa ser conectado manualmente
`\Tainacan\Pages::load_page()` só é chamado se a subclasse fizer `add_action( 'load-' . $page_suffix, ... )` após o `add_submenu_page()`. Sem isso, **CSS e JS não carregam** e a página fica sem formatação. Já está corrigido no `add_admin_menu()`.

### 3. Permission callbacks REST devem ser PUBLIC
WordPress REST API exige callbacks públicos. Toda a `REST_Controller` segue isso.

### 4. Saída de comando no Windows vem em CP850
O CMD do Windows usa CP850 / Windows-1252 por padrão, mas o REST/JSON espera UTF-8. Sem conversão, mensagens de erro aparecem com `?` no lugar de acentos. **`Processor::to_utf8()` resolve isso** — não remova essa função.

### 5. PATH do Apache no Windows
Mudanças no PATH do sistema só chegam ao Apache do XAMPP **após reiniciá-lo pelo painel**. Esse é o erro #1 dos usuários Windows. Está documentado em `docs/INSTALL.md`.

### 6. Não usar `--clean` no Windows
A flag `--clean` do OCRmyPDF requer `unpaper`, que não tem build oficial Windows. Em Windows, deixe a checkbox "Limpeza de ruído" desmarcada.

### 7. Idempotência
O meta `_tainacan_ocr_processed` marca anexos já processados. A fila pula esses por padrão (controlado por checkbox na UI).

## Endpoints REST

Namespace: `tainacan-ocr-search/v1`

| Método | Path | Função |
|---|---|---|
| GET | `/check` | Verifica Tesseract + OCRmyPDF + idiomas instalados |
| GET | `/settings` | Lê opções salvas |
| POST | `/settings` | Salva opções |
| GET | `/queue?collection_id=&only_pending=` | Lista IDs de anexos elegíveis |
| POST | `/process` | Processa um anexo (`attachment_id`) |
| GET | `/collections` | Lista coleções Tainacan publicadas |

Todas exigem `manage_options`.

## Metadados de post (anexo)

| Meta key | Conteúdo |
|---|---|
| `_tainacan_ocr_processed` | Timestamp do processamento (existe = pulado em reprocessamentos) |
| `_tainacan_ocr_text` | Texto puro extraído após OCR (alimenta a busca via Tainacan reindex) |
| `_tainacan_ocr_log` | Últimos 2KB do stderr/stdout do OCRmyPDF para troubleshooting |

## Opções salvas (`get_option('tainacan_ocr_search_options')`)

```php
[
    'tesseract_path' => '',     // vazio = usa PATH
    'ocrmypdf_path'  => '',
    'language'       => 'por',  // 'por', 'eng', 'por+eng'
    'auto_process'   => 0,      // 1 = OCR automático em novos uploads
    'deskew'         => 1,
    'clean'          => 1,      // desmarcar no Windows
    'force_ocr'      => 0,      // 0 = --skip-text, 1 = --force-ocr
]
```

## Como rodar o build / testes

Não há build — é PHP + JS vanilla. Testes manuais:

1. Ative o plugin no WordPress.
2. Instale dependências conforme `docs/INSTALL.md`.
3. Crie uma coleção Tainacan de teste.
4. Suba 2-3 PDFs/imagens escaneadas como itens.
5. Vá em **Tainacan → OCR Search**, siga os 4 passos.
6. Após o lote, rode `wp tainacan index-content --collection=all`.
7. Volte à coleção e busque por uma palavra que aparece no documento — deve aparecer.

## Histórico de versões

- **1.0.0** — primeira versão: bootstrap, página admin (4 passos), processador, REST, UI.
  - Bug corrigido durante o dev: construtor protegido de `\Tainacan\Pages` — virou singleton.
  - Bug corrigido durante o dev: `load_page()` não era chamado — adicionado hook `load-{$suffix}`.
- **1.0.1** — encoding + documentação:
  - `Processor::to_utf8()` para normalizar saída do CMD do Windows.
  - `docs/INSTALL.md` com instruções detalhadas Linux/macOS/Windows.
  - Card "Como instalar?" linka para o guia completo.

## Ideias para próximas versões

- [ ] **Anonimização automática** com regex (CPF, RG, CNS, telefone) e/ou NER (spaCy/Presidio) antes de salvar o texto — crítico se o acervo for público.
- [ ] **Botão "Reprocessar" individual** na tela de edição do anexo (metabox).
- [ ] **WP-CLI command** próprio: `wp tainacan-ocr process --collection=N`.
- [ ] **Modelo treinado para manuscritos** (Kraken/Calamari) como alternativa ao Tesseract, para letra de médico.
- [ ] **Integração com ElasticPress** — flag para enviar o texto OCR direto para o índice Elasticsearch quando ativo.
- [ ] **Estatísticas no dashboard**: % de itens processados por coleção.
- [ ] **Tradução** EN/ES.

## Convenções

- Idioma da UI e mensagens: **português brasileiro**.
- Text domain: `tainacan-ocr-search`.
- Namespace PHP: `Tainacan_OCR_Search\`.
- Prefixo CSS: `tnc-ocr-`.
- Prefixo JS global: `TainacanOCRSearch`.
- Prefixo de meta: `_tainacan_ocr_`.

## Dependências externas (não bundled)

| Software | Mínimo | Onde |
|---|---|---|
| WordPress | 6.0 | `Requires at least` |
| PHP | 7.4 | `Requires PHP` |
| Tainacan | qualquer versão recente que tenha `\Tainacan\Pages` | hard dep |
| Tesseract OCR | 4.x+ | sistema |
| OCRmyPDF | 14.x+ | sistema (via pip) |
| Ghostscript | 9.x+ | sistema |
| Python | 3.8+ | sistema |
| Poppler | qualquer | sistema (opcional) |
| PHP Imagick | qualquer | extensão PHP (opcional, só para imagens soltas) |

## Contato e referências

- Documentação Tainacan para criar admin pages: https://tainacan.github.io/tainacan-wiki/#/dev/creating-tainacan-admin-pages
- Tainacan Sample Plugin: https://github.com/tainacan/tainacan-sample-plugin
- OCRmyPDF: https://github.com/ocrmypdf/OCRmyPDF
- Tesseract: https://github.com/tesseract-ocr/tesseract
- Discussão da comunidade Tainacan sobre OCR em PDFs: https://tainacan.discourse.group/t/conteudo-do-pdf-feito-com-ocr-nao-aparece-nas-pesquisas/2170/7
