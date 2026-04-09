# Guia de Instalação das Dependências

O **Tainacan OCR Search** depende de dois utilitários livres rodando no servidor:

| Software | Função |
|---|---|
| **Tesseract OCR** | Engine de reconhecimento óptico (extrai texto de imagens) |
| **OCRmyPDF** | Wrapper que adiciona uma camada de texto pesquisável a PDFs sem alterar o visual |

E, como dependências secundárias do OCRmyPDF:

| Software | Função |
|---|---|
| **Ghostscript** | Manipula e otimiza os PDFs |
| **Python 3.8+** | Runtime do OCRmyPDF |
| **Poppler / pdftotext** *(opcional)* | Extração de texto puro mais rápida |
| **PHP Imagick** *(opcional)* | Converter imagens soltas (JPG/PNG/TIFF) em PDF antes do OCR |

> **Importante**: depois de instalar qualquer dependência, **reinicie o servidor web (Apache/nginx/php-fpm)**. O processo PHP herda variáveis de ambiente apenas no momento em que é iniciado.

---

## Linux (Debian / Ubuntu)

Tudo via APT, em um único comando:

```bash
sudo apt update
sudo apt install -y \
    tesseract-ocr \
    tesseract-ocr-por \
    tesseract-ocr-eng \
    ocrmypdf \
    ghostscript \
    poppler-utils \
    unpaper
```

Para habilitar o Imagick no PHP (se for processar imagens soltas):

```bash
sudo apt install -y php-imagick
sudo systemctl restart apache2   # ou php8.x-fpm + nginx
```

Validação:

```bash
tesseract --version
tesseract --list-langs           # deve listar 'por' e 'eng'
ocrmypdf --version
gs --version
pdftotext -v
```

### Linux (Fedora / RHEL / Rocky)

```bash
sudo dnf install -y \
    tesseract \
    tesseract-langpack-por \
    tesseract-langpack-eng \
    ocrmypdf \
    ghostscript \
    poppler-utils \
    unpaper \
    php-pecl-imagick
sudo systemctl restart httpd
```

### Linux (Arch / Manjaro)

```bash
sudo pacman -S tesseract tesseract-data-por tesseract-data-eng \
               ocrmypdf ghostscript poppler unpaper
```

---

## macOS (com Homebrew)

```bash
brew install tesseract tesseract-lang ocrmypdf ghostscript poppler unpaper
```

`tesseract-lang` instala todos os idiomas oficiais (incluindo `por`).

Para habilitar Imagick no PHP do Homebrew:

```bash
brew install imagemagick
pecl install imagick
brew services restart php
```

Validação:

```bash
tesseract --version
tesseract --list-langs
ocrmypdf --version
gs --version
pdftotext -v
```

> Se usar **MAMP** em vez de PHP do Homebrew, o `php.ini` fica em `/Applications/MAMP/bin/php/phpX.Y.Z/conf/php.ini`. Reinicie os serviços do MAMP após qualquer alteração.

---

## Windows (com XAMPP)

No Windows o processo é mais manual: cada utilitário tem seu próprio instalador e é preciso adicionar caminhos ao `PATH` do sistema.

### 1. Tesseract OCR

1. Baixe o instalador **UB-Mannheim** (build oficial mantido para Windows):
   <https://github.com/UB-Mannheim/tesseract/wiki>
   Pegue `tesseract-ocr-w64-setup-x.x.x.exe` (64-bit).

2. Execute o instalador. Em **"Choose components"** → **"Additional language data"** marque:
   - `Portuguese` (essencial)
   - `English` (recomendado)

3. Caminho padrão: `C:\Program Files\Tesseract-OCR`. **Anote**.

4. Adicione ao **PATH do sistema**:
   - Win+S → "variáveis de ambiente" → **Editar variáveis de ambiente do sistema** → **Variáveis de Ambiente**
   - Em **Path** (Variáveis do **sistema**, não do usuário) → **Novo** → cole: `C:\Program Files\Tesseract-OCR`
   - **OK** em todas as janelas.

5. Abra um **terminal NOVO** e teste:
   ```cmd
   tesseract --version
   tesseract --list-langs
   ```

### 2. Python 3

1. Baixe Python 3.11 ou 3.12: <https://www.python.org/downloads/windows/>
2. Na primeira tela do instalador marque **"Add python.exe to PATH"**.
3. **Install Now**.
4. Em terminal novo:
   ```cmd
   python --version
   pip --version
   ```

### 3. Ghostscript

1. Baixe a versão **AGPL 64-bit**: <https://www.ghostscript.com/releases/gsdnld.html>
2. Instale com opções padrão.
3. Adicione ao PATH (mesmo procedimento do Tesseract):
   ```
   C:\Program Files\gs\gs10.04.0\bin
   ```
   *(ajuste o número da versão para o que foi instalado)*
4. Teste:
   ```cmd
   gswin64c --version
   ```

### 4. OCRmyPDF

```cmd
pip install --upgrade pip
pip install ocrmypdf
```

Teste:
```cmd
ocrmypdf --version
```

> **Se aparecer "ocrmypdf não é reconhecido"**: o diretório `Scripts` do Python não está no PATH. Adicione algo como:
> `C:\Users\SEU_USUARIO\AppData\Local\Programs\Python\Python312\Scripts`

### 5. Poppler (opcional, para `pdftotext`)

1. Baixe: <https://github.com/oschwartz10612/poppler-windows/releases/>
2. Extraia para `C:\poppler\`.
3. Adicione `C:\poppler\Library\bin` ao PATH.
4. Teste: `pdftotext -v`

### 6. PHP Imagick no XAMPP (opcional, só para imagens soltas)

1. Verifique a versão exata do PHP do XAMPP:
   ```cmd
   C:\xampp82\php\php.exe -v
   ```
2. Baixe o binário Imagick correspondente em <https://windows.php.net/downloads/pecl/releases/imagick/>
   - Versão para o seu PHP (ex.: `8.2`)
   - Arquitetura `x64`
   - **Thread Safe (TS)** — o XAMPP é TS
3. Copie:
   - `php_imagick.dll` → `C:\xampp82\php\ext\`
   - Todas as `CORE_RL_*.dll` do ZIP → `C:\xampp82\php\` (raiz)
4. Edite `C:\xampp82\php\php.ini` e adicione:
   ```ini
   extension=imagick
   ```
5. Reinicie o Apache pelo painel do XAMPP.

### 7. Reinicie o Apache do XAMPP

**Crítico**: pelo painel do XAMPP, clique **Stop** no Apache e depois **Start** novamente. Sem reiniciar, o PHP continua usando o PATH antigo e o plugin reportará "tesseract não encontrado" mesmo com tudo instalado.

### 8. Validação no plugin

1. Acesse **WordPress Admin → Tainacan → OCR Search**.
2. Passo 1 → **Verificar agora**.
3. Se aparecer ✓ verde para Tesseract e OCRmyPDF, está pronto.
4. Se aparecer ✗ mas tudo funciona no CMD, vá ao **Passo 2** e informe o **caminho completo** do executável:
   - Tesseract: `C:\Program Files\Tesseract-OCR\tesseract.exe`
   - OCRmyPDF: `C:\Users\SEU_USUARIO\AppData\Local\Programs\Python\Python312\Scripts\ocrmypdf.exe`
5. Salve e verifique novamente.

---

## Validação rápida (todas as plataformas)

Em um terminal **novo**, rode:

```bash
tesseract --version
tesseract --list-langs
ocrmypdf --version
gs --version          # no Windows: gswin64c --version
pdftotext -v
```

Se os 5 responderem sem erro, o ambiente está pronto.

---

## Problemas comuns

| Sintoma | Causa | Solução |
|---|---|---|
| Plugin diz "tesseract não encontrado" mas funciona no CMD/terminal | O servidor web não foi reiniciado após mudar o PATH | Reinicie Apache/nginx/php-fpm |
| Saída do `--version` aparece com `?` no lugar de acentos (Windows) | CMD usa CP850 enquanto PHP/JSON espera UTF-8 | Já corrigido na versão 1.0.1 — atualize o plugin |
| `ocrmypdf` falha com "Could not find Ghostscript" | Ghostscript não está no PATH do servidor web | Adicione ao PATH **do sistema** e reinicie o Apache |
| OCR muito lento | Tesseract usa 1 core por padrão; OCRmyPDF paraleliza páginas | Para acervos grandes, processe em lote durante a noite ou aumente `--jobs` (avançado) |
| Erro `--clean` precisa do unpaper | `unpaper` não tem build oficial Windows | Desmarque "Limpeza de ruído" no Passo 2 do plugin — `--deskew` sozinho já ajuda muito |
| `Permission denied` no Linux | usuário do Apache (`www-data`) sem acesso ao binário | `sudo chmod +x` no binário ou reinstalar via apt |
| `Decryption error` no OCRmyPDF | PDF protegido por senha | Remova a proteção antes (`qpdf --decrypt`) |
| Erro "input file is encrypted" | Idem | Idem |

---

## Por que essa stack?

- **100% software livre** (Apache, MPL, GPL).
- **100% on-premises** — nenhum documento sai do servidor (essencial para LGPD em fichas médicas).
- **Tesseract** é mantido pelo Google desde 2006 e é o engine OCR livre mais preciso disponível.
- **OCRmyPDF** preserva o visual original do PDF (importante para valor probatório/arquivístico) e gera PDF/A.
