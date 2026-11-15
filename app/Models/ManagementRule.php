<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category in the company's Rules catalogue (the "Rules" tab of the
 * client's file). Each category maps to ONE P&L group + subgroup, and
 * that mapping decides where every document's cost lands in the P&L.
 *
 * Each company has its OWN list (seeded from the file, then editable).
 * Groups that start with "EXCLUIR" never touch the P&L — CapEx,
 * Passivo and Financiamento are tracked in the Costs tabs only.
 *
 * Company scoping follows this app's convention: `created_by` holds
 * the creator/company user id (see User::creatorId()).
 */
class ManagementRule extends Model
{
    protected $fillable = [
        'created_by',
        'code',
        'name',
        'group',
        'subgroup',
        'sort_order',
    ];

    /** P&L groups a category can belong to (the file's Groups). */
    public const PL_GROUPS = [
        'CMVM Food',
        'CMVM Beverage',
        'CMVM Staff Food',
        'Custos Variáveis',
        'Pessoal - Remunerações',
        'Pessoal - Impostos',
        'Custos Fixos',
        'Outros Custos / Pontuais',
        'EXCLUIR (CapEx)',
        'EXCLUIR (Passivo)',
        'EXCLUIR (Financiamento)',
    ];

    /** Groups that never reach the P&L (tracked in Costs only). */
    public const EXCLUDED_GROUPS = [
        'EXCLUIR (CapEx)',
        'EXCLUIR (Passivo)',
        'EXCLUIR (Financiamento)',
    ];

    /** The subgroup that marks delivery-partner commissions. */
    public const DELIVERY_SUBGROUP = 'Comissões Delivery';

    public function documents(): HasMany
    {
        return $this->hasMany(ManagementDocument::class);
    }

    /** Display label, as in the file: "6263 - Seguros". */
    public function label(): string
    {
        return $this->name !== '' ? "{$this->code} - {$this->name}" : $this->code;
    }

    public function isExcluded(): bool
    {
        return in_array($this->group, self::EXCLUDED_GROUPS, true);
    }

    /**
     * The canonical catalogue, copied verbatim from the Rules tab of
     * PnL2026_Baseline.xlsx (rows 6-78; 57 non-empty categories — the
     * file's 73 rows include 16 blank lines). One source of truth: the
     * seeder and ensureSeeded() both read this.
     */
    public static function defaultCatalogue(): array
    {
        return [
            ['code' => '312_01', 'name' => 'Mercearia', 'group' => 'CMVM Food', 'subgroup' => 'Food', 'sort_order' => 10],
            ['code' => '312_02', 'name' => 'Produtos Importados', 'group' => 'CMVM Food', 'subgroup' => 'Food', 'sort_order' => 11],
            ['code' => '312_03', 'name' => 'Frutas e Legumes', 'group' => 'CMVM Food', 'subgroup' => 'Food', 'sort_order' => 12],
            ['code' => '312_04', 'name' => 'Peixe', 'group' => 'CMVM Food', 'subgroup' => 'Food', 'sort_order' => 13],
            ['code' => '312_05', 'name' => 'Carne', 'group' => 'CMVM Food', 'subgroup' => 'Food', 'sort_order' => 14],
            ['code' => '312_06', 'name' => 'Sobremesas', 'group' => 'CMVM Food', 'subgroup' => 'Food', 'sort_order' => 15],
            ['code' => '312_07', 'name' => 'Águas, Refrigerantes e Outros', 'group' => 'CMVM Beverage', 'subgroup' => 'Beverage', 'sort_order' => 20],
            ['code' => '312_08', 'name' => 'Café', 'group' => 'CMVM Beverage', 'subgroup' => 'Beverage', 'sort_order' => 21],
            ['code' => '312_09', 'name' => 'Vinho e Bebidas Alcoólicas', 'group' => 'CMVM Beverage', 'subgroup' => 'Beverage', 'sort_order' => 22],
            ['code' => '312_11', 'name' => 'Comida Staff', 'group' => 'CMVM Staff Food', 'subgroup' => 'Food', 'sort_order' => 25],
            ['code' => '6225_01', 'name' => 'Delivery Partner', 'group' => 'Custos Variáveis', 'subgroup' => 'Comissões Delivery', 'sort_order' => 30],
            ['code' => '6227', 'name' => 'Serviços Bancários', 'group' => 'Custos Variáveis', 'subgroup' => 'Bancos/TPA', 'sort_order' => 31],
            ['code' => '6251_3', 'name' => 'Deslocações', 'group' => 'Custos Variáveis', 'subgroup' => 'Transporte Op.', 'sort_order' => 32],
            ['code' => '632', 'name' => 'Remunerações Pessoal', 'group' => 'Pessoal - Remunerações', 'subgroup' => 'Remunerações', 'sort_order' => 40],
            ['code' => '638', 'name' => 'Outros Gastos Com Pessoal', 'group' => 'Pessoal - Remunerações', 'subgroup' => 'Outros', 'sort_order' => 41],
            ['code' => '6381', 'name' => 'Fardamento', 'group' => 'Pessoal - Remunerações', 'subgroup' => 'Fardamento', 'sort_order' => 42],
            ['code' => '631', 'name' => 'Remuneração Dos Órgãos Sociais', 'group' => 'Pessoal - Remunerações', 'subgroup' => 'Órgãos Sociais', 'sort_order' => 43],
            ['code' => '245', 'name' => 'Estado - Segurança Social', 'group' => 'Pessoal - Impostos', 'subgroup' => 'SS', 'sort_order' => 50],
            ['code' => '2421', 'name' => 'Estado - Retenção IRS (Salários)', 'group' => 'Pessoal - Impostos', 'subgroup' => 'IRS a pagar', 'sort_order' => 51],
            ['code' => '6261', 'name' => 'Rendas E Alugueres', 'group' => 'Custos Fixos', 'subgroup' => 'Ocupação', 'sort_order' => 61],
            ['code' => '6263', 'name' => 'Seguros', 'group' => 'Custos Fixos', 'subgroup' => 'Ocupação', 'sort_order' => 62],
            ['code' => '62612', 'name' => 'Outros Alugueres', 'group' => 'Custos Fixos', 'subgroup' => 'Operating', 'sort_order' => 63],
            ['code' => '626141', 'name' => 'Alugueres Veiculos s/ TA', 'group' => 'Custos Fixos', 'subgroup' => 'Operating', 'sort_order' => 64],
            ['code' => '6267', 'name' => 'Limpeza, Higiene e Conforto', 'group' => 'Custos Fixos', 'subgroup' => 'Operating', 'sort_order' => 65],
            ['code' => '6241', 'name' => 'Eletricidade', 'group' => 'Custos Fixos', 'subgroup' => 'Utilities', 'sort_order' => 66],
            ['code' => '6243', 'name' => 'Água Rede', 'group' => 'Custos Fixos', 'subgroup' => 'Utilities', 'sort_order' => 67],
            ['code' => '6244', 'name' => 'Gás', 'group' => 'Custos Fixos', 'subgroup' => 'Utilities', 'sort_order' => 68],
            ['code' => '6242', 'name' => 'Combustíveis', 'group' => 'Custos Fixos', 'subgroup' => 'Utilities', 'sort_order' => 69],
            ['code' => '6262', 'name' => 'Comunicação/Telecomunicação', 'group' => 'Custos Fixos', 'subgroup' => 'Telecom', 'sort_order' => 70],
            ['code' => '6223', 'name' => 'Vigilância E Segurança', 'group' => 'Custos Fixos', 'subgroup' => 'Operating', 'sort_order' => 71],
            ['code' => '6231', 'name' => 'Ferramentas E Utensílios Des.Ráp.', 'group' => 'Outros Custos / Pontuais', 'subgroup' => 'Operating', 'sort_order' => 91],
            ['code' => '6233', 'name' => 'Material de Escritório', 'group' => 'Custos Fixos', 'subgroup' => 'Operating', 'sort_order' => 72],
            ['code' => '6251_1', 'name' => 'Estadias (Ex: Hoteis)', 'group' => 'Custos Fixos', 'subgroup' => 'Deslocações', 'sort_order' => 73],
            ['code' => '6251_2', 'name' => 'Refeições', 'group' => 'Custos Fixos', 'subgroup' => 'Deslocações', 'sort_order' => 74],
            ['code' => '6252', 'name' => 'Transportes De Pessoal', 'group' => 'Custos Fixos', 'subgroup' => 'Transportes', 'sort_order' => 75],
            ['code' => '6253', 'name' => 'Transporte de Mercadorias', 'group' => 'Custos Fixos', 'subgroup' => 'Transportes', 'sort_order' => 76],
            ['code' => '6254', 'name' => 'Portagens e Estacionamento', 'group' => 'Custos Fixos', 'subgroup' => 'Transportes', 'sort_order' => 77],
            ['code' => '6221', 'name' => 'Trabalhos Especializados', 'group' => 'Custos Fixos', 'subgroup' => 'Serv. Profiss.', 'sort_order' => 78],
            ['code' => '62212', 'name' => 'Serviços de Informática', 'group' => 'Custos Fixos', 'subgroup' => 'Serv. Profiss.', 'sort_order' => 79],
            ['code' => '6224_01', 'name' => 'Advocacia', 'group' => 'Custos Fixos', 'subgroup' => 'Serv. Profiss.', 'sort_order' => 80],
            ['code' => '6224_02', 'name' => 'Contabilidade', 'group' => 'Custos Fixos', 'subgroup' => 'Serv. Profiss.', 'sort_order' => 81],
            ['code' => '6224_03', 'name' => 'Marketing e Comunicação', 'group' => 'Custos Fixos', 'subgroup' => 'Marketing', 'sort_order' => 82],
            ['code' => '6222', 'name' => 'Publicidade E Propaganda', 'group' => 'Custos Fixos', 'subgroup' => 'Marketing', 'sort_order' => 83],
            ['code' => '6228', 'name' => 'Outros', 'group' => 'Custos Fixos', 'subgroup' => 'Outros', 'sort_order' => 84],
            ['code' => '2424', 'name' => 'Estado - Retenção IRS (Rendas)', 'group' => 'Custos Fixos', 'subgroup' => 'Ocupação', 'sort_order' => 60],
            ['code' => '241', 'name' => 'Estado - IRC a pagar', 'group' => 'Outros Custos / Pontuais', 'subgroup' => 'IRC', 'sort_order' => 90],
            ['code' => '6226', 'name' => 'Conservação E Reparação', 'group' => 'Outros Custos / Pontuais', 'subgroup' => 'Manutenção', 'sort_order' => 92],
            ['code' => '432', 'name' => 'Edificios e Outras Construções', 'group' => 'EXCLUIR (CapEx)', 'subgroup' => 'CapEx', 'sort_order' => 99],
            ['code' => '433', 'name' => 'Equipamento Básico', 'group' => 'EXCLUIR (CapEx)', 'subgroup' => 'CapEx', 'sort_order' => 99],
            ['code' => '64214', 'name' => 'Equipamento de transporte', 'group' => 'Outros Custos / Pontuais', 'subgroup' => 'Ativos Fixos', 'sort_order' => 97],
            ['code' => '243', 'name' => 'Estado - IVA', 'group' => 'EXCLUIR (Passivo)', 'subgroup' => 'IVA', 'sort_order' => 99],
            ['code' => '244', 'name' => 'Estado - Outros Impostos', 'group' => 'EXCLUIR (Passivo)', 'subgroup' => 'Impostos', 'sort_order' => 99],
            ['code' => 'Emprestimos MLP', 'name' => '', 'group' => 'EXCLUIR (Financiamento)', 'subgroup' => 'Dívida', 'sort_order' => 99],
            ['code' => '636', 'name' => 'Seguros De Acidentes No Trabalho', 'group' => 'Pessoal - Remunerações', 'subgroup' => 'Seg. Acidentes', 'sort_order' => 53],
            ['code' => '433_1', 'name' => 'Equipamento Informático', 'group' => 'EXCLUIR (CapEx)', 'subgroup' => 'CapEx', 'sort_order' => 99],
            ['code' => '435_1', 'name' => 'Equip. De Escritório / Adm.', 'group' => 'EXCLUIR (CapEx)', 'subgroup' => 'CapEx', 'sort_order' => 99],
            ['code' => '6225', 'name' => 'Comissões', 'group' => 'Custos Variáveis', 'subgroup' => 'Comissões', 'sort_order' => 33],
        ];
    }

    /**
     * Seed the default catalogue for a company that has no rules yet.
     * Called from the seeder (fresh installs) and lazily from the
     * controllers/services (existing companies, e.g. on the temp site).
     */
    public static function ensureSeeded(int $creatorId): void
    {
        if (static::where('created_by', $creatorId)->exists()) {
            return;
        }

        foreach (static::defaultCatalogue() as $row) {
            static::create(array_merge($row, ['created_by' => $creatorId]));
        }
    }
}
