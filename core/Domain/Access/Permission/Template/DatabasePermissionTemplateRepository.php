<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;

final readonly class DatabasePermissionTemplateRepository implements PermissionTemplateRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function find(PermissionTemplateKey $key): ?PermissionTemplate
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `template_key`, `name`, `description`, `is_system` '
            . 'FROM `forwext_permission_templates` WHERE `template_key` = :template_key LIMIT 1',
            ['template_key' => $key->value()],
        ));

        if ($row === null) {
            return null;
        }

        return $this->hydrateTemplate($row, $this->ruleRows($key));
    }

    public function all(): array
    {
        $templates = $this->database->fetchAll(new CompiledQuery(
            'SELECT `template_key`, `name`, `description`, `is_system` '
            . 'FROM `forwext_permission_templates` ORDER BY `sort_order`, `template_key`',
        ));

        if ($templates === []) {
            return [];
        }

        $ruleRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT tr.`template_key`, tr.`permission_key`, p.`value_type`, tr.`effect`, tr.`numeric_limit` '
            . 'FROM `forwext_permission_template_rules` tr '
            . 'INNER JOIN `forwext_permissions` p ON p.`permission_key` = tr.`permission_key` '
            . 'ORDER BY tr.`template_key`, tr.`permission_key`',
        ));

        $rulesByTemplate = [];
        foreach ($ruleRows as $ruleRow) {
            $rulesByTemplate[(string) $ruleRow['template_key']][] = $ruleRow;
        }

        $result = [];
        foreach ($templates as $templateRow) {
            $key = (string) $templateRow['template_key'];
            $result[] = $this->hydrateTemplate($templateRow, $rulesByTemplate[$key] ?? []);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function ruleRows(PermissionTemplateKey $key): array
    {
        return $this->database->fetchAll(new CompiledQuery(
            'SELECT tr.`template_key`, tr.`permission_key`, p.`value_type`, tr.`effect`, tr.`numeric_limit` '
            . 'FROM `forwext_permission_template_rules` tr '
            . 'INNER JOIN `forwext_permissions` p ON p.`permission_key` = tr.`permission_key` '
            . 'WHERE tr.`template_key` = :template_key ORDER BY tr.`permission_key`',
            ['template_key' => $key->value()],
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $ruleRows
     */
    private function hydrateTemplate(array $row, array $ruleRows): PermissionTemplate
    {
        $rules = array_map(
            static function (array $ruleRow): PermissionTemplateRule {
                $definition = new PermissionDefinition(
                    PermissionKey::fromString((string) $ruleRow['permission_key']),
                    PermissionValueType::from((string) $ruleRow['value_type']),
                );
                $limit = $ruleRow['numeric_limit'] ?? null;

                return new PermissionTemplateRule(
                    $definition,
                    PermissionEffect::from((string) $ruleRow['effect']),
                    $limit === null ? null : (int) $limit,
                );
            },
            $ruleRows,
        );

        return new PermissionTemplate(
            PermissionTemplateKey::fromString((string) $row['template_key']),
            (string) $row['name'],
            (string) $row['description'],
            (bool) $row['is_system'],
            $rules,
        );
    }
}
