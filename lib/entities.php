<?php
// entities.php - field definitions for the generic list/edit pages
// field: [label, type, extra]  types: text, textarea, int, num, money, date, bool, enum(extra=options), fk(extra=entity)
declare(strict_types=1);

const UNIT_KIND = ['apartment' => 'Wohnung', 'parking' => 'Stellplatz', 'garage' => 'Garage', 'cellar' => 'Keller', 'commercial' => 'Gewerbe', 'other' => 'Sonstiges'];
const WEG_KIND = ['apartment' => 'Wohnung', 'whole_house' => 'ganzes Haus', 'parking' => 'Stellplätze', 'garage' => 'Garage', 'other' => 'Sonstiges'];
const ALLOC_KEY = ['area' => 'Wohnfläche', 'units' => 'Wohneinheiten', 'persons' => 'Personen', 'consumption' => 'Verbrauch (Zähler)', 'mea' => 'MEA', 'direct' => 'direkt (Einheit)'];
const WEG_KEY = ['mea' => 'MEA', 'units' => 'Einheiten', 'area' => 'Wohnfläche', 'direct' => 'direkt', 'none' => 'keine Umlage'];
const METER_KIND = ['water_cold' => 'Kaltwasser', 'water_hot' => 'Warmwasser', 'heat' => 'Wärme / HKV', 'electricity' => 'Strom', 'gas' => 'Gas', 'oil' => 'Öl', 'pv' => 'PV', 'other' => 'Sonstiges'];
const READING_REASON = ['regular' => 'Stichtag', 'move_in' => 'Einzug', 'move_out' => 'Auszug', 'exchange' => 'Zählertausch', 'estimate' => 'Schätzung'];
const PRIORITY = ['low' => 'niedrig', 'normal' => 'normal', 'high' => 'hoch', 'emergency' => 'Notfall'];
const TICKET_STATUS = ['open' => 'offen', 'in_progress' => 'in Arbeit', 'waiting' => 'wartet', 'done' => 'erledigt', 'rejected' => 'abgelehnt'];
const BEARER = ['community' => 'Gemeinschaft', 'owner' => 'Eigentümer', 'tenant' => 'Mieter', 'insurance' => 'Versicherung', 'warranty' => 'Gewährleistung'];
const POSTING_KIND = ['charge' => 'Soll (Forderung)', 'payment' => 'Zahlung (Ist)'];
const INVEST_KIND = ['land' => 'Grundstück', 'purchase' => 'Kauf Gebäude', 'renovation' => 'Sanierung / Modernisierung', 'extension' => 'Ausbau / Neubau', 'other' => 'Sonstiges'];

function entities(): array {
    static $E = null;
    if ($E) return $E;
    $E = [
    'property' => ['title' => 'Liegenschaften', 'one' => 'Liegenschaft', 'order' => 'name', 'icon' => 'bi-buildings',
        'fields' => ['name' => ['Bezeichnung', 'text'], 'street' => ['Straße', 'text'], 'zip' => ['PLZ', 'text'], 'city' => ['Ort', 'text'],
            'parcel' => ['Flurstück', 'text'], 'land_area' => ['Grundstück m²', 'num'], 'note' => ['Notiz', 'textarea']],
        'list' => ['name', 'street', 'city', 'parcel'],
        'children' => [['building', 'property_id'], ['weg_unit', 'property_id'], ['investment', 'property_id']]],
    'building' => ['title' => 'Häuser', 'one' => 'Haus', 'order' => 'name', 'icon' => 'bi-house',
        'fields' => ['property_id' => ['Liegenschaft', 'fk', 'property'], 'name' => ['Bezeichnung', 'text'], 'street' => ['Anschrift', 'text'],
            'built_year' => ['Baujahr', 'int'], 'heating' => ['Heizung', 'text'], 'note' => ['Notiz', 'textarea']],
        'list' => ['name', 'street', 'heating'],
        'children' => [['unit', 'building_id'], ['meter', 'building_id'], ['asset', 'building_id'], ['maintenance_plan', 'building_id'], ['ticket', 'building_id']]],
    'weg_unit' => ['title' => 'WEG-Einheiten', 'one' => 'WEG-Einheit', 'order' => 'CAST(no AS UNSIGNED)', 'icon' => 'bi-diagram-3',
        'fields' => ['property_id' => ['Liegenschaft', 'fk', 'property'], 'no' => ['Nr.', 'text'], 'kind' => ['Art', 'enum', WEG_KIND],
            'building_id' => ['Untergemeinschaft (Haus)', 'fk', 'building'], 'mea' => ['MEA', 'num'], 'mea_base' => ['MEA-Basis', 'num'],
            'description' => ['Beschreibung', 'text'], 'owner_id' => ['Eigentümer', 'fk', 'person'],
            'hausgeld_monthly' => ['Hausgeld/Monat', 'money'], 'reserve_monthly' => ['davon Rücklage/Monat', 'money'],
            'grundbuch' => ['Grundbuch (Blatt)', 'text'], 'note' => ['Notiz', 'textarea']],
        'list' => ['no', 'kind', 'building_id', 'mea', 'description', 'owner_id', 'hausgeld_monthly'],
        'children' => [['unit', 'weg_unit_id'], ['posting', 'weg_unit_id']]],
    'unit' => ['title' => 'Einheiten', 'one' => 'Einheit', 'order' => "kind='apartment' DESC, kind, LPAD(no,4,'0')", 'icon' => 'bi-door-open',
        'fields' => ['property_id' => ['Liegenschaft', 'fk', 'property'], 'building_id' => ['Haus', 'fk', 'building'], 'no' => ['Nr.', 'text'],
            'kind' => ['Art', 'enum', UNIT_KIND], 'floor' => ['Geschoss', 'text'], 'position' => ['Lage', 'text'],
            'living_area' => ['Wohnfläche m²', 'num'], 'net_area' => ['Nutzfläche m²', 'num'], 'rooms' => ['Zimmer', 'num'],
            'weg_unit_id' => ['WEG-Einheit', 'fk', 'weg_unit'], 'te_no' => ['Nr. lt. TE-Entwurf', 'text'],
            'mandatory_parking' => ['Pflichtstellplatz', 'bool'], 'ext_ref' => ['Fremd-Nr. (Messdienst / Altverwaltung)', 'text'], 'note' => ['Notiz', 'textarea']],
        'list' => ['no', 'kind', 'building_id', 'floor', 'position', 'living_area', 'weg_unit_id'],
        'filters' => ['kind', 'building_id'],
        'children' => [['lease', 'unit_id'], ['meter', 'unit_id'], ['ticket', 'unit_id'], ['asset', 'unit_id']]],
    'person' => ['title' => 'Personen', 'one' => 'Person', 'order' => 'COALESCE(last_name, company)', 'icon' => 'bi-people',
        'fields' => ['is_tenant' => ['Mieter', 'bool'], 'is_owner' => ['Eigentümer', 'bool'], 'is_vendor' => ['Dienstleister', 'bool'],
            'company' => ['Firma', 'text'], 'salutation' => ['Anrede', 'text'], 'first_name' => ['Vorname', 'text'], 'last_name' => ['Nachname', 'text'],
            'street' => ['Straße', 'text'], 'zip' => ['PLZ', 'text'], 'city' => ['Ort', 'text'], 'email' => ['E-Mail', 'text'],
            'phone' => ['Telefon', 'text'], 'mobile' => ['Mobil', 'text'], 'iban' => ['IBAN', 'text'], 'bic' => ['BIC', 'text'],
            'trade' => ['Gewerk', 'text'], 'note' => ['Notiz', 'textarea']],
        'list' => ['last_name', 'first_name', 'company', 'trade', 'phone', 'email', 'iban', 'is_tenant', 'is_vendor'],
        'filters' => ['is_tenant', 'is_owner', 'is_vendor'],
        'children' => [['lease', 'tenant_id'], ['weg_unit', 'owner_id']]],
    'lease' => ['title' => 'Mietverträge', 'one' => 'Mietvertrag', 'order' => 'unit_id, start_date', 'icon' => 'bi-file-earmark-text',
        'fields' => ['unit_id' => ['Einheit', 'fk', 'unit'], 'tenant_id' => ['Mieter', 'fk', 'person'], 'co_tenant' => ['Mitmieter', 'text'],
            'start_date' => ['Beginn', 'date'], 'end_date' => ['Ende', 'date'], 'persons' => ['Personen', 'int'],
            'deposit' => ['Kaution Soll', 'money'], 'deposit_paid' => ['Kaution gezahlt', 'money'], 'deposit_account' => ['Kautionskonto', 'text'],
            'due_day' => ['fällig am (Tag)', 'int'], 'parking_unit_id' => ['Stellplatz', 'fk', 'unit'], 'ext_account' => ['Konto Altverwaltung', 'text'],
            'no_alloc' => ['nicht vereinbarte Kostenarten (Kürzel, z. B. TV,GA)', 'text'],
            'note' => ['Notiz', 'textarea']],
        'list' => ['unit_id', 'tenant_id', 'start_date', 'end_date', 'persons', 'ext_account'],
        'filters' => ['unit_id', 'tenant_id', 'active'],
        'children' => [['lease_rent', 'lease_id'], ['posting', 'lease_id']]],
    'lease_rent' => ['title' => 'Mietentwicklung', 'one' => 'Mietstand', 'order' => 'lease_id, valid_from', 'icon' => 'bi-graph-up',
        'fields' => ['lease_id' => ['Mietvertrag', 'fk', 'lease'], 'valid_from' => ['gültig ab', 'date'], 'base_rent' => ['Kaltmiete', 'money'],
            'operating_advance' => ['NK-Vorauszahlung', 'money'], 'heating_advance' => ['HK-Vorauszahlung', 'money'],
            'parking_rent' => ['Stellplatzmiete', 'money'], 'reason' => ['Grund', 'text']],
        'list' => ['lease_id', 'valid_from', 'base_rent', 'operating_advance', 'heating_advance', 'parking_rent', 'reason']],
    'posting' => ['title' => 'Buchungen', 'one' => 'Buchung', 'order' => 'posting_date DESC, id DESC', 'icon' => 'bi-journal-text',
        'fields' => ['lease_id' => ['Mietvertrag', 'fk', 'lease'], 'weg_unit_id' => ['WEG-Einheit', 'fk', 'weg_unit'], 'posting_date' => ['Datum', 'date'],
            'kind' => ['Art', 'enum', POSTING_KIND], 'category' => ['Kategorie', 'enum', CATEGORY_LABEL], 'amount' => ['Betrag', 'money'],
            'period' => ['Monat (JJJJ-MM)', 'text'], 'text' => ['Text', 'text'], 'source' => ['Quelle', 'text']],
        'list' => ['posting_date', 'lease_id', 'weg_unit_id', 'kind', 'category', 'amount', 'text'],
        'filters' => ['lease_id', 'weg_unit_id', 'kind', 'category']],
    'cost_type' => ['title' => 'Kostenarten', 'one' => 'Kostenart', 'order' => 'sort', 'icon' => 'bi-tags',
        'fields' => ['code' => ['Kürzel', 'text'], 'name' => ['Bezeichnung', 'text'], 'betrkv_no' => ['BetrKV § 2 Nr.', 'text'],
            'allocatable' => ['umlagefähig', 'bool'], 'alloc_key' => ['Schlüssel Mieter', 'enum', ALLOC_KEY],
            'consumption_share' => ['Verbrauchsanteil %', 'num'], 'meter_kind' => ['Zählerart', 'enum', ['' => '–', 'water_total' => 'Wasser gesamt (Kalt- + Warmwasser)'] + METER_KIND],
            'is_heating' => ['Heizkosten (HeizkostenV)', 'bool'], 'heat_plant' => ['Kosten der zentralen Heizanlage (§ 9-Aufteilung)', 'bool'],
            'allocatable_until' => ['umlagefähig bis (z. B. Kabel-TV 30.06.2024)', 'date'],
            'tax35a' => ['§ 35a EStG', 'enum', ['none' => '–', 'service' => 'haushaltsnahe Dienstleistung (Abs. 2)', 'craft' => 'Handwerkerleistung (Abs. 3)']],
            'weg_key' => ['Schlüssel WEG', 'enum', WEG_KEY], 'sort' => ['Sortierung', 'int']],
        'list' => ['code', 'name', 'betrkv_no', 'allocatable', 'alloc_key', 'consumption_share', 'meter_kind', 'heat_plant', 'tax35a', 'allocatable_until', 'weg_key']],
    'expense' => ['title' => 'Ausgaben / Rechnungen', 'one' => 'Ausgabe', 'order' => 'invoice_date DESC, id DESC', 'icon' => 'bi-receipt',
        'fields' => ['property_id' => ['Liegenschaft', 'fk', 'property'], 'building_id' => ['Haus (leer = alle)', 'fk', 'building'],
            'unit_id' => ['direkt: Einheit', 'fk', 'unit'], 'weg_unit_id' => ['direkt: WEG-Einheit', 'fk', 'weg_unit'],
            'cost_type_id' => ['Kostenart', 'fk', 'cost_type'], 'vendor_id' => ['Lieferant', 'fk', 'person'],
            'invoice_date' => ['Rechnungsdatum', 'date'], 'invoice_no' => ['Rechnungs-Nr.', 'text'], 'period_from' => ['Zeitraum von', 'date'],
            'period_to' => ['Zeitraum bis', 'date'], 'amount' => ['Betrag brutto', 'money'], 'labour_cost' => ['davon Lohn (§ 35a)', 'money'],
            'paid_date' => ['bezahlt am', 'date'], 'from_reserve' => ['aus Rücklage', 'bool'], 'text' => ['Text', 'text'], 'source' => ['Quelle', 'text']],
        'list' => ['invoice_date', 'cost_type_id', 'vendor_id', 'building_id', 'period_from', 'period_to', 'amount', 'text'],
        'filters' => ['cost_type_id', 'building_id', 'vendor_id', 'year']],
    'expected_invoice' => ['title' => 'Erwartete Rechnungen', 'one' => 'erwartete Rechnung', 'order' => 'next_expected', 'icon' => 'bi-calendar-check',
        'fields' => ['property_id' => ['Liegenschaft', 'fk', 'property'], 'building_id' => ['Haus', 'fk', 'building'], 'vendor_id' => ['Lieferant', 'fk', 'person'],
            'cost_type_id' => ['Kostenart', 'fk', 'cost_type'], 'title' => ['Bezeichnung', 'text'], 'interval_months' => ['Intervall (Monate)', 'int'],
            'next_expected' => ['nächste erwartet', 'date'], 'typical_amount' => ['üblicher Betrag', 'money'], 'active' => ['aktiv', 'bool'], 'note' => ['Notiz', 'textarea']],
        'list' => ['title', 'vendor_id', 'cost_type_id', 'interval_months', 'next_expected', 'typical_amount', 'active']],
    'weg_budget' => ['title' => 'Wirtschaftsplan', 'one' => 'Planposition', 'order' => 'year DESC, building_id, cost_type_id', 'icon' => 'bi-calculator',
        'fields' => ['property_id' => ['Liegenschaft', 'fk', 'property'], 'year' => ['Jahr', 'int'], 'building_id' => ['Untergemeinschaft (leer = alle)', 'fk', 'building'],
            'cost_type_id' => ['Kostenart (leer = Rücklage)', 'fk', 'cost_type'], 'amount' => ['Jahresbetrag', 'money'], 'note' => ['Notiz', 'text']],
        'list' => ['year', 'building_id', 'cost_type_id', 'amount', 'note'], 'filters' => ['year']],
    'investment' => ['title' => 'Investitionen (AHK)', 'one' => 'Investition', 'order' => 'acquired_date, id', 'icon' => 'bi-piggy-bank',
        'fields' => ['property_id' => ['Liegenschaft', 'fk', 'property'], 'building_id' => ['Haus (leer = ganze Liegenschaft)', 'fk', 'building'],
            'unit_ids' => ['nur Einheiten (IDs, kommagetrennt; leer = alle Wohnungen nach Wohnfläche)', 'text'],
            'kind' => ['Art', 'enum', INVEST_KIND], 'title' => ['Bezeichnung', 'text'], 'acquired_date' => ['Anschaffung / Fertigstellung', 'date'],
            'amount' => ['Anschaffungs-/Herstellungskosten', 'money'], 'afa_rate' => ['AfA % p. a. (0 = keine)', 'num'],
            'source' => ['Quelle (z. B. Bilanzkonto)', 'text'], 'note' => ['Notiz', 'textarea']],
        'list' => ['acquired_date', 'kind', 'title', 'building_id', 'unit_ids', 'amount', 'afa_rate', 'source'],
        'filters' => ['kind', 'building_id']],
    'bank_rule' => ['title' => 'Bankregeln', 'one' => 'Bankregel', 'order' => 'priority, pattern', 'icon' => 'bi-funnel',
        'fields' => ['pattern' => ['Suchtext (in Name/Verwendungszweck)', 'text'], 'direction' => ['Richtung', 'enum', ['any' => 'beide', 'in' => 'Eingang', 'out' => 'Ausgang']],
            'action' => ['Aktion', 'enum', ['payment' => 'Zahlung auf Mieter-/WEG-Konto', 'expense' => 'Ausgabe (Kostenart)', 'ignore' => 'ignorieren (privat / Umbuchung)']],
            'lease_id' => ['Mietvertrag', 'fk', 'lease'], 'weg_unit_id' => ['WEG-Einheit', 'fk', 'weg_unit'], 'cost_type_id' => ['Kostenart', 'fk', 'cost_type'],
            'vendor_id' => ['Lieferant', 'fk', 'person'], 'building_id' => ['Haus', 'fk', 'building'], 'priority' => ['Priorität (klein = zuerst)', 'int'], 'note' => ['Notiz', 'text']],
        'list' => ['pattern', 'direction', 'action', 'lease_id', 'cost_type_id', 'vendor_id', 'priority']],
    'meter' => ['title' => 'Zähler', 'one' => 'Zähler', 'order' => 'building_id, unit_id, kind', 'icon' => 'bi-speedometer2',
        'fields' => ['building_id' => ['Haus', 'fk', 'building'], 'unit_id' => ['Einheit (leer = Hauptzähler)', 'fk', 'unit'], 'kind' => ['Art', 'enum', METER_KIND],
            'purpose' => ['Zweck (Hauptzähler)', 'enum', ['' => '–', 'ww_energy' => 'Wärmezähler Warmwasserbereitung (§ 9)', 'heat_total' => 'Wärmemenge gesamt', 'fuel' => 'Brennstoff (Gas/Öl)']],
            'number' => ['Zählernummer', 'text'], 'location' => ['Ort', 'text'], 'calibrated_until' => ['Eichfrist bis', 'date'],
            'factor' => ['Faktor', 'num'], 'active' => ['aktiv', 'bool'], 'note' => ['Notiz', 'textarea']],
        'list' => ['building_id', 'unit_id', 'kind', 'number', 'location', 'calibrated_until', 'active'],
        'filters' => ['building_id', 'unit_id', 'kind'],
        'children' => [['meter_reading', 'meter_id']]],
    'meter_reading' => ['title' => 'Zählerstände', 'one' => 'Zählerstand', 'order' => 'meter_id, reading_date DESC', 'icon' => 'bi-123',
        'fields' => ['meter_id' => ['Zähler', 'fk', 'meter'], 'reading_date' => ['Datum', 'date'], 'value' => ['Stand', 'num'],
            'reason' => ['Anlass', 'enum', READING_REASON], 'note' => ['Notiz', 'text']],
        'list' => ['meter_id', 'reading_date', 'value', 'reason', 'note'], 'filters' => ['meter_id']],
    'asset' => ['title' => 'Anlagen / Inventar', 'one' => 'Anlage', 'order' => 'building_id, category, name', 'icon' => 'bi-tools',
        'hint' => 'Nur Wirtschaftsgüter über 800 € netto (GWG-Grenze, § 6 Abs. 2 EStG).',
        'fields' => ['building_id' => ['Haus', 'fk', 'building'], 'unit_id' => ['Einheit', 'fk', 'unit'], 'name' => ['Bezeichnung', 'text'],
            'category' => ['Kategorie', 'text'], 'manufacturer' => ['Hersteller', 'text'], 'model' => ['Modell', 'text'], 'serial_no' => ['Seriennummer', 'text'],
            'location' => ['Standort', 'text'], 'installed' => ['eingebaut', 'date'], 'warranty_until' => ['Gewährleistung bis', 'date'], 'note' => ['Notiz', 'textarea']],
        'list' => ['name', 'category', 'building_id', 'unit_id', 'manufacturer', 'installed', 'warranty_until'],
        'filters' => ['building_id', 'category'],
        'children' => [['maintenance_plan', 'asset_id'], ['ticket', 'asset_id']]],
    'maintenance_plan' => ['title' => 'Wartungsplan', 'one' => 'Wartung', 'order' => 'next_due IS NULL, next_due, building_id', 'icon' => 'bi-calendar2-week',
        'fields' => ['building_id' => ['Haus', 'fk', 'building'], 'asset_id' => ['Anlage', 'fk', 'asset'], 'title' => ['Aufgabe', 'text'],
            'interval_months' => ['Intervall (Monate)', 'int'], 'last_done' => ['zuletzt', 'date'], 'next_due' => ['nächste Fälligkeit', 'date'],
            'vendor_id' => ['Dienstleister', 'fk', 'person'], 'legal_basis' => ['Grundlage', 'text'], 'allocatable' => ['Kosten umlagefähig', 'bool'],
            'active' => ['aktiv', 'bool'], 'note' => ['Notiz', 'textarea']],
        'list' => ['title', 'building_id', 'interval_months', 'last_done', 'next_due', 'vendor_id', 'legal_basis'],
        'filters' => ['building_id', 'active'],
        'children' => [['maintenance_log', 'plan_id']]],
    'maintenance_log' => ['title' => 'Wartungsprotokoll', 'one' => 'Protokoll', 'order' => 'done_date DESC', 'icon' => 'bi-check2-square',
        'fields' => ['plan_id' => ['Wartung', 'fk', 'maintenance_plan'], 'done_date' => ['erledigt am', 'date'], 'vendor_id' => ['Dienstleister', 'fk', 'person'],
            'cost' => ['Kosten', 'money'], 'result' => ['Ergebnis', 'text']],
        'list' => ['plan_id', 'done_date', 'vendor_id', 'cost', 'result']],
    'ticket' => ['title' => 'Vorgänge / Schäden', 'one' => 'Vorgang', 'order' => "FIELD(status,'open','in_progress','waiting','done','rejected'), FIELD(priority,'emergency','high','normal','low'), created_at DESC", 'icon' => 'bi-exclamation-triangle',
        'fields' => ['title' => ['Titel', 'text'], 'building_id' => ['Haus', 'fk', 'building'], 'unit_id' => ['Einheit', 'fk', 'unit'], 'asset_id' => ['Anlage', 'fk', 'asset'],
            'reported_by' => ['gemeldet von', 'fk', 'person'], 'description' => ['Beschreibung', 'textarea'], 'priority' => ['Priorität', 'enum', PRIORITY],
            'status' => ['Status', 'enum', TICKET_STATUS], 'vendor_id' => ['beauftragt', 'fk', 'person'], 'created_at' => ['gemeldet am', 'date'],
            'due_date' => ['zu erledigen bis', 'date'], 'closed_at' => ['abgeschlossen', 'date'], 'cost' => ['Kosten', 'money'], 'bearer' => ['Kostenträger', 'enum', ['' => '–'] + BEARER]],
        'list' => ['created_at', 'title', 'building_id', 'unit_id', 'priority', 'status', 'vendor_id', 'due_date'],
        'filters' => ['status', 'building_id', 'priority', 'open']],
    ];
    return $E;
}

/** label SQL for foreign key display */
function fk_label_sql(string $entity, string $alias): string {
    return match ($entity) {
        'person' => str_replace(['company', 'last_name', 'first_name'], ["$alias.company", "$alias.last_name", "$alias.first_name"], PERSON_LABEL_SQL),
        'unit' => "CONCAT(CASE $alias.kind WHEN 'apartment' THEN 'Whg ' WHEN 'garage' THEN 'Garage ' WHEN 'parking' THEN 'Stp ' ELSE '' END, $alias.no)",
        'weg_unit' => "CONCAT('WEG Nr. ', $alias.no)",
        'lease' => "(SELECT CONCAT(CASE u.kind WHEN 'apartment' THEN 'Whg ' ELSE '' END, u.no, ' – ', " . str_replace(['company', 'last_name', 'first_name'], ['pp.company', 'pp.last_name', 'pp.first_name'], PERSON_LABEL_SQL) . ", IF($alias.end_date IS NOT NULL, CONCAT(' (bis ', DATE_FORMAT($alias.end_date,'%d.%m.%Y'), ')'), '')) FROM unit u, person pp WHERE u.id=$alias.unit_id AND pp.id=$alias.tenant_id)",
        'cost_type' => "CONCAT($alias.code, ' – ', $alias.name)",
        'meter' => "CONCAT($alias.kind, ' ', COALESCE($alias.number,''), ' ', COALESCE((SELECT CONCAT('Whg ', no) FROM unit WHERE id=$alias.unit_id),'Haupt'))",
        'maintenance_plan' => "CONCAT($alias.title, ' (', COALESCE((SELECT name FROM building WHERE id=$alias.building_id),''), ')')",
        'asset', 'building', 'property' => "$alias.name",
        default => "$alias.id",
    };
}

function fk_options(string $entity): array {
    static $cache = [];
    if (isset($cache[$entity])) return $cache[$entity];
    $E = entities();
    $order = $E[$entity]['order'] ?? 'id';
    $opts = [];
    foreach (rows("SELECT t.id, " . fk_label_sql($entity, 't') . " AS label FROM `$entity` t ORDER BY $order") as $r) $opts[$r['id']] = $r['label'];
    return $cache[$entity] = $opts;
}
