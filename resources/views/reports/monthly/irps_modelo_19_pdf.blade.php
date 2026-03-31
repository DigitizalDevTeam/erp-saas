<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('IRPS Modelo 19') }}</title>
    <style>
        @page { size: 267mm 330mm; margin: 6mm; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #111;
            margin: 0;
        }
        .header-table,
        .main-table,
        .sign-table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-table td,
        .main-table td,
        .main-table th,
        .sign-table td {
            border: 1px solid #444;
            padding: 4px 6px;
            vertical-align: middle;
        }
        .header-olive {
            background: #ffffbb;
            font-weight: 700;
        }
        .header-olive td {
            padding: 15px 0px;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .small { font-size: 9px; }
        .big { font-size: 18px; }
        .block-title {
            font-weight: 700;
            font-size: 10px;
            background: #ffffbb;
        }
        .block-title-2 {
            font-weight: 700;
            font-size: 10px;
            background: #dddddd;
        }
        .code-cell {
            width: 23px;
            text-align: center;
            font-weight: 700;
            background: #808080;
        }
        .value-cell {
            width: 160px;
            text-align: right;
            font-size: 16px;
            line-height: 1;
        }
        .space-row td { height: 28px; }
        .amount-row td { height: 26px;border: none !important; }
        .muted-line {
            color: #444;
            letter-spacing: 1px;
        }
        .without-border td {
            border-top: none !important;
            border-bottom: none !important;
            padding: 10px;
            font-size: 8.5px;
        }
        .addr-row {
            width: 100%;
            font-size: 8.5px;
            white-space: nowrap;
        }
        .addr-field {
            display: inline-block;
            vertical-align: middle;
        }
        .addr-line {
            display: inline-block;
            border-bottom: 1px dotted #555;
            height: 7px;
            vertical-align: middle;
            margin-left: 3px;
            margin-right: 8px;
        }
    </style>
</head>
<body>
@php
    $authUser = \Auth::user()->creatorId();
    $user = \App\Models\User::find($authUser);
    $settings = \App\Models\Utility::settings();
    $companyName = $settings['company_name'] ?? ($user?->name ?? '');
    $companyNuit = $settings['vat_number'] ?? '';
    $monthRef = \Carbon\Carbon::createFromFormat('Y-m', $filter['month'])->format('m/Y');
    $today = now()->format('d');
    $monthNow = now()->format('m');
    $yearNow = now()->format('Y');

    $line10 = (float) ($totals['irps'] ?? 0);
    $line11 = 0.0;
    $line13 = 0.0;
    $line14 = 0.0;
    $line15 = 0.0;
    $line16 = 0.0;
    $line20 = 0.0;
    $line21 = 0.0;
    $line22 = 0.0;
    $line30 = $line10 + $line11 + $line13 + $line14 + $line15 + $line16 + $line20 + $line21 + $line22;
@endphp

<div class="sheet">
    <table class="header-table">
        <tr class="header-olive center">
            <td style="width:38%;font-size: 9px;">
                <div>Republica de Mocambique</div>
                <div>Ministerio da Economia e Financas</div>
                <div>Autoridade Tributaria de Mocambique</div>
                <div>DIRECCAO GERAL DOS IMPOSTOS</div>
            </td>
            <td style="width:42%;">
                <div style="">IRPS - Imposto Sobre Rendimento das Pessoas Singulares</div>
                <div>(arts.83 e 87 do CIRPS)</div>
                <div style="">GUIA DE PAGAMENTO</div>
            </td>
            <td style="width:20%;" class="center">
                <div style="font-weight:700;">M/19</div>
                <div style="font-weight:700;">IRPS</div>
                <div class="big">{{ $monthRef }}</div>
            </td>
        </tr>
    </table>

    <table class="main-table">
        <tr class="block-title">
            <td style="width:70%;">1 - NOME / DESIGNACAO SOCIAL DO SUJEITO PASSIVO</td>
            <td style="width:30%;">2 - NUIT</td>
        </tr>
        <tr class="space-row">
            <td>{{ $companyName }}</td>
            <td>{{ $companyNuit }}</td>
        </tr>

        <tr class="block-title">
            <td colspan="2">3 - DISTRITOS COMERCIAIS USADOS (SE EXISTIREM)</td>
        </tr>
        <tr class="space-row"><td colspan="2"></td></tr>

        <tr class="block-title">
            <td colspan="2">4 - ENDERECO DA RESIDENCIA DO PROPRIETARIO / SEDE DA EMPRESA (EM MOCAMBIQUE)</td>
        </tr>
        <tr class="without-border">
            <td colspan="2">
                <div class="addr-row">
                    <span class="addr-field">Rua / Praca / Avenida</span><span class="addr-line" style="width:28%;"></span>
                    <span class="addr-field">n°</span><span class="addr-line" style="width:10%;"></span>
                    <span class="addr-field">Andar</span><span class="addr-line" style="width:10%;"></span>
                    <span class="addr-field">Localidade</span><span class="addr-line" style="width:28%; margin-right:0;"></span>
                </div>
            </td>
        </tr>
        <tr class="without-border">
            <td colspan="2">
                <div class="addr-row">
                    <span class="addr-field">Bairro</span><span class="addr-line" style="width:35%;"></span>
                    <span class="addr-field">Quarteirao</span><span class="addr-line" style="width:13%;"></span>
                    <span class="addr-field">Celula</span><span class="addr-line" style="width:13%;"></span>
                    <span class="addr-field">Flat Nr</span><span class="addr-line" style="width:20%; margin-right:0;"></span>
                </div>
            </td>
        </tr>
        <tr class="without-border"  >
            <td colspan="2">
                <div class="addr-row">
                    <span class="addr-field">Provincia</span><span class="addr-line" style="width:34%;"></span>
                    <span class="addr-field">Distrito</span><span class="addr-line" style="width:16%;"></span>
                    <span class="addr-field">Fax</span><span class="addr-line" style="width:16%;"></span>
                    <span class="addr-field">Cod. Postal</span><span class="addr-line" style="width:14%; margin-right:0;"></span>
                </div>
            </td>
        </tr>
        <tr class="without-border"  >
            <td colspan="2">
                <div class="addr-row">
                    <span class="addr-field">DAF</span><span class="addr-line" style="width:30%;"></span>
                    <span class="addr-field">Telefone Fixo</span><span class="addr-line" style="width:18%;"></span>
                    <span class="addr-field">Telemovel</span><span class="addr-line" style="width:18%;"></span>
                    <span class="addr-field">E-Mail</span><span class="addr-line" style="width:13%; margin-right:0;"></span>
                </div>
            </td>
        </tr>

        <tr class="block-title">
            <td colspan="2">5 - IMPOSTO A PAGAR</td>
        </tr>
    </table>

    {{-- SECTION 5: IMPOSTO A PAGAR (3-COLUMN LAYOUT) --}}
    <table class="main-table" style="margin-top:-1px;border-left:1px solid #444;border-right:1px solid #444;">
        <tr>
            <td style="width:30%;border:none !important;" class="small">
                <b>Reservado aos Serviços</b><br>
                <b>Classificador Económico da Receita</b>
            </td>
            <td style="width:40%;border:none !important;" class="center small"><b>TIPO DE RENDIMENTO</b></td>
            <td style="width:30%;border:none !important;" class="center small"><b>IMPORTANCIA A PAGAR EM METICAIS (sem centavos)</b></td>
        </tr>

        {{-- Linha 10 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-1</div>
            </td>
            <td>Trabalho Dependente</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">10</td>
                        <td class="value-cell">{{ number_format($line10, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 11 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-1</div>
            </td>
            <td>Pensões</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">11</td>
                        <td class="value-cell">{{ number_format($line11, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 13 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-2</div>
            </td>
            <td>Rendimentos Empresariais</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">13</td>
                        <td class="value-cell">{{ number_format($line13, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 14 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-3</div>
            </td>
            <td>Ganho de Jogos e similares (alínea e) n.1 art. 83</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">14</td>
                        <td class="value-cell">{{ number_format($line14, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 15 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-4</div>
            </td>
            <td>Rendimentos de Capitais e Mais Valias</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">15</td>
                        <td class="value-cell">{{ number_format($line15, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 16 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-5</div>
            </td>
            <td>Rendimentos Prediais</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">16</td>
                        <td class="value-cell">{{ number_format($line16, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha em branco (-----) 1 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-2</div>
            </td>
            <td><span class="muted-line">-----------------------------------------------</span></td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell"></td>
                        <td class="value-cell">0,00</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha em branco (-----) 2 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-2</div>
            </td>
            <td><span class="muted-line">-----------------------------------------------</span></td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell"></td>
                        <td class="value-cell">0,00</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 20 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-2</div>
            </td>
            <td>Pagamento por conta</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">20</td>
                        <td class="value-cell">{{ number_format($line20, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 21 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-2</div>
            </td>
            <td>Juros Compensatórios</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">21</td>
                        <td class="value-cell">{{ number_format($line21, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Linha 22 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-2</div>
            </td>
            <td>Outros</td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">22</td>
                        <td class="value-cell">{{ number_format($line22, 2, ',', '.') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Total a Pagar 30 --}}
        <tr class="amount-row">
            <td class="center">
                <div style="border:2px solid #444; padding:6px 4px;">10-10-10-20-6</div>
            </td>
            <td class="right"><b>Total a Pagar</b></td>
            <td>
                <table style="width:100%; border-collapse:collapse; border: 2px solid #444;">
                    <tr>
                        <td class="code-cell">30</td>
                        <td class="value-cell"><b>{{ number_format($line30, 2, ',', '.') }}</b></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="sign-table">
        <tr class="block-title-2 center">
            <td style="width:37%;">AUTENTICACAO DO SUJEITO PASSIVO</td>
            <td style="width:31%;">USO EXCLUSIVO DOS SERVICOS</td>
            <td style="width:32%;">USO EXCLUSIVO DOS SERVICOS</td>
        </tr>
        <tr>
            <td class="small">
                Data {{ $today }} / {{ $monthNow }} / {{ $yearNow }}<br><br>
                Nome <span class="muted-line">...............................................................................</span><br><br>
                Ass: <span class="muted-line">................................................................................</span><br><br>
                Qualidade (Gerente, Procurador, etc) <span class="muted-line">..........................................</span>
            </td>
            <td class="small">
                N de Registo <span class="muted-line">..........................................................</span><br><br>
                Data da recepcao <span class="muted-line">...................................................</span><br><br>
                Nome do Funcionario <span class="muted-line">................................................</span><br><br>
                Ass: <span class="muted-line">...................................................................</span>
            </td>
            <td class="small">
                N de Registo <span class="muted-line">..........................................................</span><br><br>
                Data da recepcao <span class="muted-line">....................................................</span><br><br>
                Nome do Funcionario <span class="muted-line">................................................</span><br><br>
                Ass: <span class="muted-line">....................................................................</span>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
