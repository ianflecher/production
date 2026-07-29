<?php

namespace App\Services;

use App\Models\ProductionOrder;

/**
 * The client design questionnaire the account officer fills in at the layout
 * step, plus the ChatGPT prompt it builds so the officer can generate design
 * concepts by copy-paste.
 */
class DesignBrief
{
    /**
     * The questions, in order. `type` drives the input rendered on the form.
     *
     * @return array<string, array{label: string, type: string, help?: string, options?: array<string,string>}>
     */
    public static function questions(): array
    {
        return [
            'branding_type' => [
                'label' => 'Is this for white label branding or regular customized apparel printing?',
                'type' => 'choice',
                'options' => ['white_label' => 'White label branding', 'regular' => 'Regular customized printing'],
            ],
            'include_ic_logo' => [
                'label' => 'Would you like to include the Imprint Customs logo?',
                'type' => 'choice',
                'options' => ['yes' => 'Yes', 'no' => 'No'],
            ],
            // NOTE: the apparel type isn't asked here — it's already chosen on the
            // order (product type) and is pulled into the prompt automatically.
            'purpose' => [
                'label' => 'What is the purpose of this apparel?',
                'type' => 'text',
                'help' => 'e.g. team uniform, event giveaway, company shirt',
            ],
            'design_peg' => [
                'label' => 'Do you have a design peg or inspiration?',
                'type' => 'textarea',
                'help' => 'Photos, links, sketches or reference designs. Upload them below, or the client can email sales@imprintcustoms.ph',
                'files' => 'peg',
            ],
            'style' => [
                'label' => 'What design style do you want?',
                'type' => 'text',
                'help' => 'Minimalist, sporty, premium, streetwear, racing, corporate, playful, vintage, futuristic, clean, bold, etc.',
            ],
            'vibe' => [
                'label' => 'What vibe should the design show?',
                'type' => 'text',
                'help' => 'Professional, strong, fast, fun, elegant, energetic, premium, youthful, team spirit, etc.',
            ],
            'base_colors' => [
                'label' => 'What base colour/s do you want?',
                'type' => 'text',
                'help' => 'Black, white, navy blue, red, gray, cream, royal blue, maroon, etc.',
            ],
            'accent_colors' => [
                'label' => 'What accent colour/s do you want?',
                'type' => 'text',
                'help' => 'Gold, neon green, orange, silver, sky blue, yellow, pink, etc.',
            ],
            'avoid_colors' => [
                'label' => 'Are there colours we should avoid?',
                'type' => 'text',
            ],
            'logos' => [
                'label' => 'What logo/s will be used?',
                'type' => 'textarea',
                'help' => 'Upload the logo files below. For best output ask for high-resolution or editable files (PNG, PDF, AI, EPS, PSD), or the client can email sales@imprintcustoms.ph',
                'files' => 'logo',
            ],
            'logo_placement' => [
                'label' => 'Where do you want the logos placed?',
                'type' => 'text',
                'help' => 'Front chest, center front, back, sleeves, shoulders, collar, side panels, etc.',
            ],
            'text_content' => [
                'label' => 'What text should be included?',
                'type' => 'textarea',
                'help' => 'Brand name, team name, slogan, number, player name, event title, year, etc.',
            ],
            'text_placement' => [
                'label' => 'Where should the text be placed?',
                'type' => 'text',
            ],
            'layout_preference' => [
                'label' => 'What layout do you prefer?',
                'type' => 'choice',
                'options' => [
                    'A' => 'A. Simple and clean',
                    'B' => 'B. Full sublimation / all-over print',
                    'C' => 'C. Front-focused',
                    'D' => 'D. Back-focused',
                    'E' => 'E. Balanced front and back',
                    'F' => 'F. Loud and detailed',
                    'G' => 'G. Premium and minimal',
                ],
            ],
            'detail_level' => [
                'label' => 'Do you want the design to be plain or detailed?',
                'type' => 'choice',
                'options' => [
                    'A' => 'A. Plain / minimal',
                    'B' => 'B. Moderate details',
                    'C' => 'C. Very detailed',
                    'D' => 'D. Let the designer decide',
                ],
            ],
            'must_remember' => [
                'label' => 'Are there important details we should remember?',
                'type' => 'textarea',
                'help' => 'Make the logo big, keep it premium, avoid too many colours, follow brand colours, kid-friendly, etc.',
            ],
            'avoid_elements' => [
                'label' => 'Are there styles or elements you do NOT want?',
                'type' => 'textarea',
                'help' => 'Too crowded, cartoonish, dark colours, neon colours, skulls, flames, etc.',
            ],
        ];
    }

    /** Human-readable answer (maps choice keys back to their label). */
    public static function answerLabel(string $key, $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $q = self::questions()[$key] ?? null;

        return $q['options'][$value] ?? (string) $value;
    }

    /**
     * Build the copy-paste ChatGPT prompt from the answers. Only answered
     * questions are included so the prompt stays tight.
     */
    public static function toPrompt(array $answers, ProductionOrder $order): string
    {
        $a = fn (string $k) => self::answerLabel($k, $answers[$k] ?? null);

        $lines = [];
        $lines[] = 'You are a senior apparel graphic designer. Design a custom apparel print based on the brief below.';
        $lines[] = '';
        $lines[] = 'PRODUCT';
        // Taken straight from the order — no need to ask the client again.
        $lines[] = '- Apparel: '.($order->productLabel() ?? 'custom apparel');
        $lines[] = '- Quantity: '.number_format($order->quantity).' pcs';

        if ($p = $a('purpose')) {
            $lines[] = '- Purpose: '.$p;
        }
        if ($b = $a('branding_type')) {
            $lines[] = '- Branding: '.$b;
        }
        if ($ic = $a('include_ic_logo')) {
            $lines[] = '- Include Imprint Customs logo: '.$ic;
        }

        // Files uploaded under the peg / logo questions. The client often uploads
        // instead of typing, so the prompt must mention them either way.
        $files = $order->jobOrder?->referenceFiles ?? collect();
        $pegFiles = $files->where('kind', 'peg')->pluck('original_name');
        $logoFiles = $files->where('kind', 'logo')->pluck('original_name');

        $withFiles = function (?string $text, $names, string $noun): ?string {
            if ($names->isEmpty()) {
                return $text;
            }
            $attached = $names->count().' '.$noun.($names->count() === 1 ? '' : 's')
                .' attached ('.$names->implode(', ').')';

            return $text ? $text.' — '.$attached : ucfirst($attached);
        };

        $look = array_filter([
            'Style' => $a('style'),
            'Vibe' => $a('vibe'),
            'Base colours' => $a('base_colors'),
            'Accent colours' => $a('accent_colors'),
            'Colours to avoid' => $a('avoid_colors'),
            'Design peg / inspiration' => $withFiles($a('design_peg'), $pegFiles, 'reference file'),
        ]);
        if ($look) {
            $lines[] = '';
            $lines[] = 'LOOK AND FEEL';
            foreach ($look as $k => $v) {
                $lines[] = "- {$k}: {$v}";
            }
        }

        $content = array_filter([
            'Logos' => $withFiles($a('logos'), $logoFiles, 'logo file'),
            'Logo placement' => $a('logo_placement'),
            'Text' => $a('text_content'),
            'Text placement' => $a('text_placement'),
        ]);
        if ($content) {
            $lines[] = '';
            $lines[] = 'LOGOS AND TEXT';
            foreach ($content as $k => $v) {
                $lines[] = "- {$k}: {$v}";
            }
        }

        $comp = array_filter([
            'Layout' => $a('layout_preference'),
            'Detail level' => $a('detail_level'),
        ]);
        if ($comp) {
            $lines[] = '';
            $lines[] = 'COMPOSITION';
            foreach ($comp as $k => $v) {
                $lines[] = "- {$k}: {$v}";
            }
        }

        $rules = array_filter([
            'Must remember' => $a('must_remember'),
            'Do NOT include' => $a('avoid_elements'),
        ]);
        if ($rules) {
            $lines[] = '';
            $lines[] = 'CONSTRAINTS';
            foreach ($rules as $k => $v) {
                $lines[] = "- {$k}: {$v}";
            }
        }

        if ($pegFiles->isNotEmpty() || $logoFiles->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'ATTACHMENTS';
            if ($pegFiles->isNotEmpty()) {
                $lines[] = '- Design peg / inspiration images: '.$pegFiles->implode(', ');
            }
            if ($logoFiles->isNotEmpty()) {
                $lines[] = '- Logo files: '.$logoFiles->implode(', ');
            }
            $lines[] = '- Upload these images alongside this prompt. Follow the peg for the overall look, '
                .'and reproduce the supplied logo/s exactly — do not redraw or restyle them.';
        }

        $lines[] = '';
        $lines[] = 'OUTPUT';
        $lines[] = '- Show the front and back of the garment only, laid flat on a plain neutral background.';
        $lines[] = '- Print-ready: bold readable shapes, clean edges, and colours that hold up on fabric.';
        $lines[] = '- IMPORTANT: do NOT add any captions, titles, headings, labels, spec sheets, '
            .'"design concept" write-ups, quantity notes, size charts, colour swatches, arrows, borders, '
            .'watermarks or any other annotation around the garment.';
        $lines[] = '- The ONLY text anywhere in the image must be the text that is actually printed on the '
            .'apparel itself. Nothing above, below, or beside the garment.';

        return implode("\n", $lines);
    }
}
