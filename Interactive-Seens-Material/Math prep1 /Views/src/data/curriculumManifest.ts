export type SimulationMode = 'Comparison' | 'Process' | 'Time Evolution' | 'Parallel Flow' | 'Accumulation' | 'Concept';

export interface Section {
  id: string;
  sectionNumber: number;
  titleEn: string;
  titleAr: string;
  path: string;
  mode: string;
  description: string;
}

export interface Lesson {
  id: string;
  lessonNumber: number;
  titleEn: string;
  sections: Section[];
}

export interface Unit {
  id: string;
  unitNumber: number;
  titleEn: string;
  color: string;
  lessons: Lesson[];
}

export const curriculumManifest: Unit[] = [
  {
    id: 'u1', unitNumber: 1, titleEn: 'Numbers & Operations', color: 'bg-blue-500',
    lessons: [
      {
        id: 'u1l2', lessonNumber: 2, titleEn: 'Scale Drawings',
        sections: [
          {
            id: 'u1l2s1', sectionNumber: 1, titleEn: 'Comparison', titleAr: 'مقارنة', path: '/unit/1/lesson/2/section/1', mode: 'Comparison',
            description: "Comparison: Split-screen visual board displays insect graphic on left and pyramid graphic on right. Left panel expands insect icon from 2.5 mm real mark to 75 mm drawing mark; ratio badge highlights 30 : 1 with gold text 'Magnification (> 1)'. Right panel compresses pyramid icon from 187 m real mark to 9.35 cm drawing mark; ratio badge highlights 1 : 2000 with cyan text 'Minimization (< 1)'. Central numeric scale meter highlights boundary value 1. User toggle switches focus between magnification and minimization visuals. Final visual state highlights locked classification badges above respective scaled icons, visually correcting the misconception that scale drawings only represent map reductions."
          },
          {
            id: 'u1l2s2', sectionNumber: 2, titleEn: 'Unit Conversion Process', titleAr: 'عملية تحويل الوحدات', path: '/unit/1/lesson/2/section/2', mode: 'Process',
            description: "Process: Input display shows Drawing Length block 4 cm and Real Length block 80 km side-by-side. Unit converter node activates, transforming 80 km block into 8,000,000 cm block via multiplication particle x100,000. Fractional container aligns 4 cm over 8,000,000 cm. Divisor node 4 passes through both terms, reducing fraction into simplified ratio block 1 : 2,000,000. User slider adjusts initial kilometer value while unit converter dynamically recalculates output ratio. Final visual state displays unified unit fraction converting into simplified integer scale ratio, visually correcting the misconception of directly dividing unmatching measurement units."
          },
          {
            id: 'u1l2s3', sectionNumber: 3, titleEn: 'Proportion Resolution', titleAr: 'حل التناسب', path: '/unit/1/lesson/2/section/3', mode: 'Process',
            description: "Process: Blueprint layout board displays proportion template 1 / 500,000 = 3 cm / Real Length with unknown term highlighted in gold. Diagonal cross-multiplication ray connects 3 cm and 500,000, generating intermediate product block 1,500,000 cm. Unit reduction beam transforms 1,500,000 cm through division by 100,000, outputting final real length block 15 km onto map gauge. Interactive toggle switches to reverse solver calculating drawing length from real distance. Final visual state resolves proportion equation into final geographical distance display, visually correcting the misconception of forgetting unit reconversion back to real-world measures."
          }
        ]
      },
      {
        id: 'u1l3', lessonNumber: 3, titleEn: 'Proportional Division',
        sections: [
          {
            id: 'u1l3s1', sectionNumber: 1, titleEn: 'Division Ratios', titleAr: 'نسب التقسيم', path: '/unit/1/lesson/3/section/1', mode: 'Process',
            description: "Process: A total bar of 320 LE divides into 8 equal segment blocks. 3 blocks highlight in blue for Person A and 5 blocks highlight in orange for Person B. Each block displays unit value calculation 320 / 8 = 40 LE. Share calculated pods display 120 LE and 200 LE respectively. User interaction slider alters total quantity and ratio terms while segment blocks re-partition dynamically. Final visual state displays partitioned bar with unit values and share totals, visually correcting the misconception that division must produce equal shares."
          },
          {
            id: 'u1l3s2', sectionNumber: 2, titleEn: 'Unit Value Concept', titleAr: 'مفهوم قيمة الوحدة', path: '/unit/1/lesson/3/section/2', mode: 'Process',
            description: "Process: Total money bag 1,200 LE pours into division funnel. Ratio terms 2 : 1 combine in sum node yielding 3 total parts. Funnel computes unit part block 400 LE. Output streams direct 2 unit parts (800 LE) to Slot 1 and 1 unit part (400 LE) to Slot 2. User interactive button advances calculation stages step-by-step. Final visual state confirms dual slot balances summing to total initial 1,200 LE, visually correcting the misconception of multiplying ratios directly by total amount without finding unit part value."
          },
          {
            id: 'u1l3s3', sectionNumber: 3, titleEn: 'Capital Investment', titleAr: 'استثمار رأس المال', path: '/unit/1/lesson/3/section/3', mode: 'Process',
            description: "Process: Three investor vaults show capital inputs 250,000 LE, 200,000 LE, and 300,000 LE. Simplification module applies factor 50,000, outputting reduced ratio 5 : 4 : 6. Annual profit stack 150,000 LE divides by total parts sum 15, yielding unit part value 10,000 LE. Profit stack splits into 5, 4, and 6 coin bundles depositing into partner balance scales showing 50,000 LE, 40,000 LE, and 60,000 LE. User toggle switches between profit distribution and loss distribution. Final visual state locks balanced partner profit scales proportional to initial capital, visually correcting the misconception of dividing business profits equally despite unequal investments."
          },
          {
            id: 'u1l3s4', sectionNumber: 4, titleEn: 'Inheritance Distribution', titleAr: 'توزيع الميراث', path: '/unit/1/lesson/3/section/4', mode: 'Time Evolution',
            description: "Time Evolution: Total estate pie chart 600,000 LE displays. Primary statutory slice 1/8 detaches for Wife showing value 75,000 LE. Remaining pie segment 525,000 LE transforms into 5 ratio parts (1 Daughter part, 4 Son parts across 2 sons). Unit value pod calculates 105,000 LE per part. Daughter pod receives 105,000 LE and each Son pod receives 210,000 LE. User interactive timeline step control steps through deduction of wife share then distribution to children. Final visual state displays fully allocated estate pie chart, visually correcting the misconception of distributing children's shares before deducting statutory spouse inheritance."
          },
          {
            id: 'u1l3s5', sectionNumber: 5, titleEn: 'Geometric Proportion', titleAr: 'التناسب الهندسي', path: '/unit/1/lesson/3/section/5', mode: 'Comparison',
            description: "Comparison: Triangle perimeter wireframe displays total length 144 cm. Side ratio terms 5 : 4 : 3 combine into sum pod 12 parts. Division node computes unit part length 12 cm. Wireframe sides expand dynamically to lengths 60 cm, 48 cm, and 36 cm. Shortest side 36 cm highlights with gold border box. User ratio slider modifies side proportions while triangle geometry resizes dynamically. Final visual state locks scaled geometric wireframe with side labels matching proportional division outputs, visually correcting the misconception that side ratios represent direct linear lengths."
          }
        ]
      },
      {
        id: 'u1l4', lessonNumber: 4, titleEn: 'Applications of Percentages',
        sections: [
          {
            id: 'u1l4s1', sectionNumber: 1, titleEn: 'Discount Evaluation', titleAr: 'تقييم الخصم', path: '/unit/1/lesson/4/section/1', mode: 'Process',
            description: "Process: Price tag block 600,000 LE undergoes subtraction with new price block 510,000 LE, isolating discount segment 90,000 LE. Fraction node aligns 90,000 over base price 600,000 LE. Multiplication particle x100% transforms fraction into glowing badge 15%. User slider adjusts new price while percentage wheel dynamically recalculates discount rate. Final visual state displays isolated discount bar over base tag with verified 15% overlay, visually correcting the misconception that discount rate is calculated relative to the selling price."
          },
          {
            id: 'u1l4s2', sectionNumber: 2, titleEn: 'Split Percentage Flow', titleAr: 'تدفق النسبة المئوية', path: '/unit/1/lesson/4/section/2', mode: 'Parallel Flow',
            description: "Parallel Flow: Product price tag 18,500 LE splits into dual streams upon entering 12% discount portal. Top stream calculates 12% discount value block 2,220 LE; bottom stream calculates 88% remaining price block 16,280 LE. Both output streams display side-by-side in calculation summary box. User toggle adjusts discount percentage slider. Final visual state displays original price splitting into 12% savings and 88% net payment, visually correcting the misconception that discount value is added rather than subtracted."
          },
          {
            id: 'u1l4s3', sectionNumber: 3, titleEn: 'Restoring Original Price', titleAr: 'استعادة السعر الأصلي', path: '/unit/1/lesson/4/section/3', mode: 'Time Evolution',
            description: "Time Evolution: Sale tag 8,400 LE displays with badge '-40%'. Complementary percentage calculator generates net percentage node 60% (100% - 40%). Reverse proportion grid aligns 8,400 LE over 60%. Cross-multiplication ray multiplies 8,400 by 100 and divides by 60, expanding bar back to original 100% state of 14,000 LE. User interaction button advances timeline steps. Final visual state locks fully restored original price tag 14,000 LE, visually correcting the misconception that original price is found by adding 40% of the sale price."
          },
          {
            id: 'u1l4s4', sectionNumber: 4, titleEn: 'Price Increase Accumulation', titleAr: 'تراكم زيادة السعر', path: '/unit/1/lesson/4/section/4', mode: 'Accumulation',
            description: "Accumulation: Base price bar displays 16,000 LE. Price increase event extends bar to 18,000 LE, highlighting added extension segment 2,000 LE in green glow. Ratio module places 2,000 over base 16,000 LE, outputting 12.5% increase badge overlay. User slider modifies new price level. Final visual state highlights extension segment against base price bar with 12.5% increase label, visually correcting the misconception of using new price in denominator when computing increase rate."
          },
          {
            id: 'u1l4s5', sectionNumber: 5, titleEn: 'Tax Addition Process', titleAr: 'إضافة الضرائب', path: '/unit/1/lesson/4/section/5', mode: 'Process',
            description: "Process: Base bill block 540 LE receives tax addition layer set to 14%. Tax calculation node outputs 75.6 LE block and attaches it atop base bill bar. Combined bar expands to total length labeled 615.6 LE. Alternative direct scale overlay shows 114% multiplier beam converting 540 LE straight to 615.6 LE. User toggle switches between two-step addition view and direct 114% multiplier view. Final visual state displays stacked tax block on base bill with total 615.6 LE badge, visually correcting the misconception that tax rate reduces total payable bill."
          }
        ]
      },
      {
        id: 'u1l5', lessonNumber: 5, titleEn: 'Sets and Operations',
        sections: [
          {
            id: 'u1l5s1', sectionNumber: 1, titleEn: 'Set Representation', titleAr: 'تمثيل المجموعات', path: '/unit/1/lesson/5/section/1', mode: 'Parallel Flow',
            description: "Parallel Flow: Tray holding red, white, and black ball objects morphs into three synchronized view pods. Pod 1 displays Listing bracket {red, white, black}; Pod 2 displays Set-Builder rule {x : x is a color of the Egyptian flag}; Pod 3 draws a closed Venn diagram boundary around point dots labeled red, white, and black. Subjective non-set items float outside frame and dissolve. User view selector switches focal pod view. Final visual state locks unified three-way representation of identical set elements, visually correcting the misconception that different representation styles alter set identity."
          },
          {
            id: 'u1l5s2', sectionNumber: 2, titleEn: 'Types of Sets', titleAr: 'أنواع المجموعات', path: '/unit/1/lesson/5/section/2', mode: 'Comparison',
            description: "Comparison: Three transparent set pods sit on display platform. Pod 1 receives 4 labeled blocks with counter locking at 'Count = 4 (Finite)'. Pod 2 streams continuous numeric particle belt 1, 2, 3, 4 into infinity conduit with badge '∞ (Infinite)'. Pod 3 opens empty with glowing null symbol ∅ and counter badge 'Count = 0 (Empty Set)'. User toggle switches element generation controls across pods. Final visual state highlights three classified set pods with element counter overlays, visually correcting the misconception that the empty set contains the number zero."
          },
          {
            id: 'u1l5s3', sectionNumber: 3, titleEn: 'Set Equality', titleAr: 'تساوي المجموعات', path: '/unit/1/lesson/5/section/3', mode: 'Process',
            description: "Process: Twin set pouches A = {5, 2, 4} and B = {2, 4, 5, 5} align side-by-side. Scanning light beam pairs matching element particles 2 with 2, 4 with 4, and 5 with 5. Duplicate particle 5 in pouch B condenses into single node. Equal sign lock illuminates between pouches showing A = B. Secondary equation pouch displays A = {4, 3} and B = {3, x}, solving variable x to value 4 via matching beam. User interaction steps through element alignment phases. Final visual state locks balanced twin set pouches with equal sign overlay, visually correcting the misconception that element order or duplication alters set equality."
          },
          {
            id: 'u1l5s4', sectionNumber: 4, titleEn: 'Intersection vs Union', titleAr: 'التقاطع والاتحاد', path: '/unit/1/lesson/5/section/4', mode: 'Comparison',
            description: "Comparison: Overlapping dual Venn circles X = {1, 3, 5} and Y = {3, 5, 7} display central intersection lens containing {3, 5}. Mode toggle activates Intersection (∩), illuminating central overlapping lens in bright green while outer wings dim, outputting result box X∩Y={3,5}. Mode toggle switches to Union (∪), illuminating entire dual-circle footprint in solid yellow, outputting combined result box X∪Y={1,3,5,7}. Disjoint circles separate with zero overlap, driving intersection box to ∅. Final visual state contrasts green overlap lens against full yellow union region, visually correcting the misconception of repeating shared elements in the union set."
          }
        ]
      },
      {
        id: 'u1l6', lessonNumber: 6, titleEn: 'Operations on Integers',
        sections: [
          {
            id: 'u1l6s1', sectionNumber: 1, titleEn: 'Vector Addition', titleAr: 'جمع المتجهات', path: '/unit/1/lesson/6/section/1', mode: 'Process',
            description: "Process: Horizontal number line centered at 0 displays vector arrows. Vector 1 moves 3 units left to -3; Vector 2 attaches at -3 and extends 5 units right, resting on +2 with equation overlay (-3) + 5 = 2. Properties balance board highlights Additive Inverse cancellation of +5 and -5 into zero node. User vector slider allows moving start position and magnitude across number line. Final visual state displays vector displacement landing at +2 with confirmed property badge overlays, visually correcting the misconception that adding a positive number to a negative number always results in a negative sum."
          },
          {
            id: 'u1l6s2', sectionNumber: 2, titleEn: 'Subtraction Equivalence', titleAr: 'تكافؤ الطرح', path: '/unit/1/lesson/6/section/2', mode: 'Process',
            description: "Process: Expression display 5 - (-3) enters sign transformation conduit. Subtraction symbol - flips into addition symbol +, while negative term (-3) flips into additive inverse +3, generating equivalent expression 5 + 3 = 8. Side-by-side comparison scale contrasts 7 - 4 = 3 against 4 - 7 = -3 with red tilt alert indicating non-commutativity. User toggle switches input values to test subtraction equivalence. Final visual state displays transformed expression 5 + 3 = 8 alongside non-commutative scale alert, visually correcting the misconception that integer subtraction is commutative."
          },
          {
            id: 'u1l6s3', sectionNumber: 3, titleEn: 'Multiplication Properties', titleAr: 'خصائص الضرب', path: '/unit/1/lesson/6/section/3', mode: 'Parallel Flow',
            description: "Parallel Flow: 2x2 sign matrix illuminates positive and negative product rules. Negative particle blocks -4 and -6 enter multiplication chamber, colliding to output positive particle block +24. Distributive pipeline takes input 3(10 + 2) and branches term 3 into dual multipliers generating (3 x 10) + (3 x 2) = 30 + 6 = 36. User interaction slider alters bracketed terms while distributive branches recalculate in real-time. Final visual state highlights green positive product from dual negative collision alongside expanded distributive term branches, visually correcting the misconception that multiplying two negative numbers produces a negative product."
          },
          {
            id: 'u1l6s4', sectionNumber: 4, titleEn: 'Division Constraints', titleAr: 'قيود القسمة', path: '/unit/1/lesson/6/section/4', mode: 'Comparison',
            description: "Comparison: Division sorter conduit receives integer inputs. Input pair -20 and -4 resolves to +5 via sign cancellation. Input pair 5 and 2 hits fractional filter barrier with label 5/2 not in Z. Input pair 8 and 0 triggers red hazard alert 'Undefined / Division by Zero Impossible', while reverse pair 0 and 8 outputs green 0 block. User toggle switches numerator and denominator values. Final visual state contrasts fractional filter block and division-by-zero alert against valid integer outputs, visually correcting the misconception that division by zero equals zero."
          }
        ]
      },
      {
        id: 'u1l7', lessonNumber: 7, titleEn: 'Operations on Rational Numbers',
        sections: [
          {
            id: 'u1l7s1', sectionNumber: 1, titleEn: 'Fraction Simplification', titleAr: 'تبسيط الكسور', path: '/unit/1/lesson/7/section/1', mode: 'Process',
            description: "Process: Fraction box holding 6/8 passes through factor scanning lens, highlighting common divisor 2 in both terms. Divisor node applies reduction, outputting simplified standard fraction 3/4. Secondary input sets denominator b = 0, triggering red hazard barrier displaying 'Division by Zero Invalid (a/0 not in Q)'. User interaction slider adjusts numerator and denominator inputs while simplification pipeline converts fractions into standard form. Final visual state highlights simplified 3/4 box alongside denominator zero hazard lock, visually correcting the misconception that division by zero is defined in rational numbers."
          },
          {
            id: 'u1l7s2', sectionNumber: 2, titleEn: 'Denominator Matching', titleAr: 'توحيد المقامات', path: '/unit/1/lesson/7/section/2', mode: 'Process',
            description: "Process: Fraction pie charts 1/4 and 1/2 display side-by-side. Fraction 1/2 splits into two 1/4 slices (2/4) under LCD grid converter. Matching 1/4 slice pieces combine into unified pie chart outputting 3/4. Unmatched fraction blocks 1/3 and 1/4 pass through LCD pipeline, expanding to grid base 12 (4/12 + 3/12) to yield total 7/12 block. User toggle switches operational modes between addition and subtraction. Final visual state displays unified fractional slice grid with sum 7/12, visually correcting the misconception of adding denominators directly."
          },
          {
            id: 'u1l7s3', sectionNumber: 3, titleEn: 'Reciprocal Division', titleAr: 'القسمة عبر المقلوب', path: '/unit/1/lesson/7/section/3', mode: 'Parallel Flow',
            description: "Parallel Flow: Overlapping grid matrix displays 2/3 x 3/4, highlighting double-shaded intersection region 6/12 reduced to 1/2. Division expression 3/4 / (2/5) enters reciprocal channel; divisor 2/5 rotates 180 degrees into 5/2 while division operator morphs into multiplication sign, computing product 15/8. User interaction allows toggling terms and watching grid overlay resize dynamically. Final visual state contrasts 2D area overlap product against 180-degree flipped reciprocal division, visually correcting the misconception of dividing numerators directly without inverting the divisor."
          },
          {
            id: 'u1l7s4', sectionNumber: 4, titleEn: 'Density of Rationals', titleAr: 'كثافة الأعداد النسبية', path: '/unit/1/lesson/7/section/4', mode: 'Time Evolution',
            description: "Time Evolution: Number line displays boundary rational numbers 1/3 and 2/3. Infinity zoom lens expands interval, converting boundaries to 2/6 and 4/6 to reveal hidden midpoint 3/6 (1/2). Secondary deeper zoom expands interval to base 60, populating 9 intermediate rational number markers along segment line. Operational balance scale displays Commutative and Distributive property validations for rational inputs. User zoom control slider scrubs between macro boundary view and micro dense interval view. Final visual state shows continuously populated rational number line segment under high magnification, visually correcting the misconception that rational numbers are discrete with no values between consecutive fractions."
          }
        ]
      }
    ]
  },
  {
    id: 'u2', unitNumber: 2, titleEn: 'Algebra', color: 'bg-emerald-500',
    lessons: [
      {
        id: 'u2l1', lessonNumber: 1, titleEn: 'Algebraic Terms & Expressions',
        sections: [
          {
            id: 'u2l1s1', sectionNumber: 1, titleEn: 'Concepts of Terms', titleAr: 'مفاهيم الحدود', path: '/unit/2/lesson/1/section/1', mode: 'Concept',
            description: "Concept of Algebraic Terms and Algebraic Expressions Lec (Sec 2): Like Terms vs. Unlike Terms Lec (Sec 3): Addition of Like Algebraic Terms Lec (Sec 4): Subtraction of Like Algebraic Terms"
          },
          {
            id: 'u2l1s2', sectionNumber: 2, titleEn: 'Term Assembly', titleAr: 'تجميع الحدود', path: '/unit/2/lesson/1/section/2', mode: 'Process',
            description: "Process: Coefficient block 5 glides into assembly slot alongside variable boxes x and y^3. Exponent scanning beam highlights powers 1 and 3, adding them to output badge 'Degree = 4th Degree, Coefficient = 5'. Operational signs + and - attach neighboring terms +2x - 7 to extend term chain into multi-term expression container. User slider alters exponent values while degree badge recalculates in real-time. Final visual state displays fully assembled algebraic expression with highlighted coefficients and degree badges, visually correcting the misconception that constant terms have no degree."
          },
          {
            id: 'u2l1s3', sectionNumber: 3, titleEn: 'Like Term Sorting', titleAr: 'فرز الحدود المتشابهة', path: '/unit/2/lesson/1/section/3', mode: 'Comparison',
            description: "Comparison: Sorter conveyor belt receives term blocks 5x^2, -2x^2, 3x, and 5y. Variable template scanner overlay compares variable base and power configurations. Blocks 5x^2 and -2x^2 match variable structure x^2 perfectly, glowing green and depositing into 'Like Terms' bin. Blocks 3x and 5y fail power/symbol matching, triggering red alert barriers and dropping into 'Unlike Terms' bins. User interaction allows custom term creation to test sorter filters. Final visual state highlights sorted bins with green matched variable overlays, visually correcting the misconception that identical coefficients make terms like."
          },
          {
            id: 'u2l1s4', sectionNumber: 4, titleEn: 'Term Addition', titleAr: 'جمع الحدود', path: '/unit/2/lesson/1/section/4', mode: 'Process',
            description: "Process: Stacking workspace places 3 blue blocks stamped x^2 adjacent to 5 blue blocks stamped x^2. The two block columns merge into a single 8-block column while coefficient counter node sums 3 + 5 = 8 to display 8x^2. Non-matching block group 2x attempts entry but bounces off x^2 column due to shape mismatch. User toggle adjusts initial coefficients. Final visual state locks combined 8x^2 stack with unchanged x^2 label stamps, visually correcting the misconception that adding like terms adds their exponent powers."
          },
          {
            id: 'u2l1s5', sectionNumber: 5, titleEn: 'Verbal Phrase Subtraction', titleAr: 'طرح العبارات اللفظية', path: '/unit/2/lesson/1/section/5', mode: 'Process',
            description: "Process: Verbal phrase badge 'Subtract 3a from 8a' enters translation matrix. Matrix flips term positions, placing second term 8a in lead position and attaching subtraction operator to 3a, forming equation 8a - 3a. Laser beam subtracts 3 blocks from 8a stack, leaving 5a output block. Alternate phrase 'What is the increase of 7y over 2y' transforms into 7y - 2y = 5y. User interaction dropdown selects various word phrasing options while translator rearranges equation layout. Final visual state locks correctly ordered subtraction equations with evaluated coefficient outputs, visually correcting the misconception of maintaining direct word order during subtraction."
          }
        ]
      },
      {
        id: 'u2l2', lessonNumber: 2, titleEn: 'Adding & Subtracting Expressions',
        sections: [
          {
            id: 'u2l2s1', sectionNumber: 1, titleEn: 'Vertical Alignment', titleAr: 'المحاذاة الرأسية', path: '/unit/2/lesson/2/section/1', mode: 'Process',
            description: "Process: Two algebraic expressions 3a - 2b + 5 and 2a + 5b - 3 load onto a vertical alignment grid. Vertical guideline beams drop down, creating column 1 (a-terms), column 2 (b-terms), and column 3 (constants). Column-wise addition nodes execute vertically: 3a + 2a = 5a, -2b + 5b = +3b, and 5 - 3 = +2. Sum row illuminates in gold below horizontal bar displaying 5a + 3b + 2. User interaction allows dragging mismatched terms into correct vertical columns. Final visual state displays perfectly aligned vertical columns with evaluated sum row, visually correcting the misconception of adding terms across different variable categories."
          },
          {
            id: 'u2l2s2', sectionNumber: 2, titleEn: 'Horizontal Grouping', titleAr: 'التجميع الأفقي', path: '/unit/2/lesson/2/section/2', mode: 'Process',
            description: "Process: Horizontal expression string (5x^2 - 3x + 4) + (2x^2 + 7x - 1) displays on single timeline. Commutative grouping lens sweeps across string, drawing connecting color arcs and rearranging terms into bracketed groups: (5x^2 + 2x^2) + (-3x + 7x) + (4 - 1). Grouping pods simplify sequentially into 7x^2, +4x, and +3, generating final output string 7x^2 + 4x + 3. User slider adjusts initial expression coefficients while grouping arcs re-route dynamically. Final visual state highlights simplified bracketed terms merging into final reduced polynomial, visually correcting the misconception that horizontal addition requires changing variable exponents."
          },
          {
            id: 'u2l2s3', sectionNumber: 3, titleEn: 'Expression Subtraction', titleAr: 'طرح المقادير', path: '/unit/2/lesson/2/section/3', mode: 'Process',
            description: "Process: Verbal prompt 'Subtract 2x^2 - 5x + 3 from 6x^2 + x - 4' enters workspace. Target expression 6x^2 + x - 4 positions in top row; subtracted expression 2x^2 - 5x + 3 positions in bottom row. Sign-inverter wand sweeps across bottom row: +2x^2 flips to red -2x^2, -5x flips to green +5x, and +3 flips to red -3. Operation changes to addition, and vertical addition beams process columns to yield 4x^2 + 6x - 7. User interaction button steps through wand sweep phase. Final visual state locks inverted signs in bottom row with verified column sums, visually correcting the misconception of changing only the sign of the first term during subtraction."
          },
          {
            id: 'u2l2s4', sectionNumber: 4, titleEn: 'Complex Subtraction Phrasing', titleAr: 'عبارات الطرح المعقدة', path: '/unit/2/lesson/2/section/4', mode: 'Process',
            description: "Process: Phrase cards feed into translation engine. Card 'Increase of 5x + 3y over 2x - y' arranges expression A first minus expression B: (5x + 3y) - (2x - y). Card 'What must be added to 3x^2 - 4 to get 7x^2 + 2' flips order to target minus initial: (7x^2 + 2) - (3x^2 - 4). Sign-inverter node flips signs of subtracted terms, and addition combiner resolves final output 4x^2 + 6. Verification balance scale tests output by adding back to initial term to match target. User dropdown selects various verbal phrases to observe dynamic term reordering. Final visual state locks translated subtraction layout with verified balance check, visually correcting the misconception of maintaining direct word order for all verbal subtraction problems."
          }
        ]
      },
      {
        id: 'u2l3', lessonNumber: 3, titleEn: 'Linear Equations',
        sections: [
          {
            id: 'u2l3s1', sectionNumber: 1, titleEn: 'Equation vs Expression', titleAr: 'المعادلة مقابل المقدار', path: '/unit/2/lesson/3/section/1', mode: 'Comparison',
            description: "Comparison: Two-pan balance scale displays left pan holding 2X bags and 7 weight blocks, and right pan holding 13 weight blocks. Central balance beam aligns horizontally with equal sign lock illuminating at fulcrum. Exponent reader scans variable X^1 to display badge 'Linear Equation in One Variable (1st Degree)'. Secondary expression 2X + 4 - y floats unanchored without balance scale or equal sign. User interaction toggle switches between equation and expression modes. Final visual state locks balanced scale with illuminated equal sign and degree badge, visually correcting the misconception that an algebraic expression without an equal sign constitutes an equation."
          },
          {
            id: 'u2l3s2', sectionNumber: 2, titleEn: 'Substitution Set', titleAr: 'مجموعة التعويض', path: '/unit/2/lesson/3/section/2', mode: 'Process',
            description: "Process: Substitution Set container displays candidate values {2, 3, 4, 5}. Candidate value 3 enters testing chamber 2X - 3 = 5, evaluating to 3 != 5 with red scale tilt alert. Candidate value 4 enters testing chamber 2(4) - 3 = 5, leveling scale in green balance and transferring into Solution Set pouch S.S. = {4}. User candidate selector allows testing individual values interactively. Final visual state locks accepted solution element in Solution Set pouch nested within Substitution Set container, visually correcting the misconception that all substitution candidates satisfy the equation."
          },
          {
            id: 'u2l3s3', sectionNumber: 3, titleEn: 'Balancing Operations', titleAr: 'عمليات التوازن', path: '/unit/2/lesson/3/section/3', mode: 'Process',
            description: "Process: Balanced scale displays X + 4 on left pan and 9 on right pan. Subtraction node removes 4 weight blocks from left pan, causing left-up beam tilt. Subtraction node simultaneously removes 4 weight blocks from right pan, restoring green horizontal balance beam and isolating X = 5. Secondary multiplication demonstration doubles weights on both sides simultaneously to maintain beam level. User control buttons trigger symmetric operations on both pans. Final visual state displays isolated variable pan balanced against evaluated constant pan, visually correcting the misconception of performing an operation on only one side of an equation."
          },
          {
            id: 'u2l3s4', sectionNumber: 4, titleEn: 'Solution Set Domains', titleAr: 'مجالات مجموعة الحل', path: '/unit/2/lesson/3/section/4', mode: 'Time Evolution',
            description: "Time Evolution: Equation display 3X + 11 = 9 enters solver pipeline. Constant term +11 slides across equal sign bridge, transforming into -11 to form 3X = -2. Division node divides both sides by 3, yielding X = -2/3. Domain checker tests X = -2/3 against Integer filter Z (blocking with S.S. = empty set) and Rational filter Q (passing to display S.S. = {-2/3}). User domain selector toggles target set between N, Z, and Q. Final visual state displays domain-filtered solution set badge, visually correcting the misconception that an equation always has a non-empty solution set in every number domain."
          },
          {
            id: 'u2l3s5', sectionNumber: 5, titleEn: 'Word Problems to Equations', titleAr: 'المسائل اللفظية إلى معادلات', path: '/unit/2/lesson/3/section/5', mode: 'Process',
            description: "Process: Narrative problem card 'Rectangle perimeter = 18 cm, length is double width' enters translator engine. Geometric wireframe unfolds with width labeled X and length labeled 2X. Perimeter summation node combines sides into 2(X + 2X) = 6X = 18. Solver node evaluates X = 3, updating wireframe side labels to Width = 3 cm and Length = 6 cm. User slider adjusts target perimeter while wireframe dimensions scale dynamically. Final visual state displays evaluated geometric wireframe with verified perimeter calculation, visually correcting the misconception of equating perimeter directly to a single side length."
          }
        ]
      }
    ]
  },
  {
    id: 'u3', unitNumber: 3, titleEn: 'Geometry', color: 'bg-purple-500',
    lessons: [
      {
        id: 'u3l1', lessonNumber: 1, titleEn: 'Types of Angles',
        sections: [
          {
            id: 'u3l1s1', sectionNumber: 1, titleEn: 'Rays and Angles', titleAr: 'الأشعة والزوايا', path: '/unit/3/lesson/1/section/1', mode: 'Process',
            description: "Process: Two endpoint dots A and B connect with a ruler, displaying a finite length readout 6 cm. Ray extension arrow attaches at point B, expanding infinitely into space to display Ray AB label. Opposite ray arrow attaches at point A, extending in reverse to form Straight Line AB. Hinge node connects two rays OA and OB at vertex O, sweeping open to highlight angle sector arc AOB. User interaction controls endpoint placement and ray extension toggles. Final visual state displays labeled geometric hierarchy from segment to infinite line and angle, visually correcting the misconception that rays and lines have measurable lengths."
          },
          {
            id: 'u3l1s2', sectionNumber: 2, titleEn: 'Angle Classification', titleAr: 'تصنيف الزوايا', path: '/unit/3/lesson/1/section/2', mode: 'Time Evolution',
            description: "Time Evolution: 360-degree protractor display sits with fixed base ray OA and rotating arm OB starting at 0 degrees. Arm rotates counterclockwise, dynamically updating category badges: 0 degrees (Zero Angle), 45 degrees (Acute Angle), 90 degrees (Right Angle with green square corner), 135 degrees (Obtuse Angle), 180 degrees (Straight Angle line), and 240 degrees (Reflex Angle with outer arc). User drag handle rotates arm OB smoothly around protractor circle. Final visual state locks highlighted angle category sector matching current rotation angle, visually correcting the misconception that reflex angles are measured on interior arcs."
          },
          {
            id: 'u3l1s3', sectionNumber: 3, titleEn: 'Complementary Angles', titleAr: 'الزوايا المتتامة', path: '/unit/3/lesson/1/section/3', mode: 'Comparison',
            description: "Comparison: Right-angle frame AOB (90 degrees) displays green square corner. Interior divider ray OC splits space into adjacent angle sectors alpha (35 degrees) and beta (55 degrees). Sum calculation node displays 35 + 55 = 90 degrees with label 'Complementary Angles'. Dragging ray OC to 10 degrees updates adjacent angle to 80 degrees, keeping sum locked at 90 degrees. Final visual state highlights complementary angle pair forming complete 90-degree right angle, visually correcting the misconception that complementary angles must sum to 180 degrees."
          },
          {
            id: 'u3l1s4', sectionNumber: 4, titleEn: 'Supplementary Angles', titleAr: 'الزوايا المتكاملة', path: '/unit/3/lesson/1/section/4', mode: 'Process',
            description: "Process: Straight line AB displays straight angle arc 180 degrees. Ray OC standing on point O divides line into adjacent angles AOC (120 degrees) and COB (60 degrees). Sum calculation node evaluates 120 + 60 = 180 degrees with label 'Supplementary Angles'. Ray OC tilts dynamically to 45 degrees, expanding adjacent angle to 135 degrees. Final visual state locks adjacent supplementary angles lying on straight line AB, visually correcting the misconception that supplementary angles are formed by perpendicular lines."
          },
          {
            id: 'u3l1s5', sectionNumber: 5, titleEn: 'Opposite & Accumulated Angles', titleAr: 'الزوايا المتقابلة والمتجمعة', path: '/unit/3/lesson/1/section/5', mode: 'Parallel Flow',
            description: "Parallel Flow: Intersecting straight lines AB and CD create vertex M. Opposite angle sectors AMC (50 degrees) and BMD (50 degrees) highlight in glowing cyan with equal sign badge (= V.O.A). Adjacent angle AMD calculates 180 - 50 = 130 degrees, matching opposite angle BMC (130 degrees). Secondary point-accumulation display radiates 4 rays from point M with angle sectors 110, 80, 90, and 80 degrees, wrapping around vertex into 360-degree circle gauge. Final visual state highlights opposite angle equality alongside complete 360-degree point accumulation, visually correcting the misconception that vertically opposite angles are supplementary rather than equal."
          }
        ]
      },
      {
        id: 'u3l2', lessonNumber: 2, titleEn: 'Angle Relationships',
        sections: [
          {
            id: 'u3l2s1', sectionNumber: 1, titleEn: 'Angle Bisectors', titleAr: 'منصفات الزوايا', path: '/unit/3/lesson/2/section/1', mode: 'Process',
            description: "Process: Angle ABC (80 degrees) displays on layout grid. Glowing laser ray BD originates from vertex B and extends through interior, dividing 80-degree sector into two identical 40-degree wedges marked with star symbol badges. Formula overlay node calculates m(ABD) = m(CBD) = 80 / 2 = 40 degrees. User interaction slider alters full angle measure while bisector ray recalculates equal split wedges in real-time. Final visual state displays bisected angle container with twin 40-degree sectors and matching star badges, visually correcting the misconception that an angle bisector merely intersects an angle without dividing it into equal measures."
          },
          {
            id: 'u3l2s2', sectionNumber: 2, titleEn: 'Vertically Opposite Equations', titleAr: 'معادلات الزوايا المتقابلة بالرأس', path: '/unit/3/lesson/2/section/2', mode: 'Process',
            description: "Process: Intersecting straight lines AB and CD form vertex M. Top angle displays 135 degrees and bottom opposite angle displays expression (3X) degrees. Equal sign lock illuminates between opposite sectors with badge 'V.O.A'. Equation solver pipeline transforms 3X = 135 into X = 135 / 3 = 45. User toggle switches opposite expression variables. Final visual state highlights green opposite angle sector equality with resolved variable value X = 45, visually correcting the misconception of setting vertically opposite angles equal to 180 degrees instead of each other."
          },
          {
            id: 'u3l2s3', sectionNumber: 3, titleEn: 'Angles Around a Point', titleAr: 'الزوايا المتجمعة حول نقطة', path: '/unit/3/lesson/2/section/3', mode: 'Process',
            description: "Process: Vertex M displays 4 radiating rays forming adjacent sectors 140 degrees (blue), 100 degrees (green), 50 degrees (orange), and unknown sector X (red). 360-degree pie gauge wraps around vertex M. Calculation node evaluates X = 360 - (140 + 100 + 50) = 70 degrees, filling red sector to complete full 360-degree circle. User drag handle modifies known angle sectors while missing sector X updates dynamically to preserve 360-degree sum. Final visual state displays fully populated 360-degree pie chart gauge around vertex M, visually correcting the misconception that angles around a point sum to 180 degrees."
          },
          {
            id: 'u3l2s4', sectionNumber: 4, titleEn: 'Adjacent Angles on a Line', titleAr: 'الزوايا المتجاورة على مستقيم', path: '/unit/3/lesson/2/section/4', mode: 'Comparison',
            description: "Comparison: Adjacent angles ABD (50 degrees) and DBC (130 degrees) position side-by-side. Sum evaluation node computes 50 + 130 = 180 degrees, triggering outer rays BA and BC to flatten into a continuous straight line AC with straight-line badge. Secondary adjacent pair ABD (42 degrees) and DBC (48 degrees) compute sum 42 + 48 = 90 degrees, causing outer rays BA and BC to snap into a perpendicular square corner with perpendicular badge. User interaction allows toggling angle values to test straight line vs perpendicular outcomes. Final visual state contrasts 180-degree flat line against 90-degree perpendicular corner lock, visually correcting the misconception that any two adjacent angles form a straight line regardless of their sum."
          }
        ]
      },
      {
        id: 'u3l3', lessonNumber: 3, titleEn: 'Parallelism',
        sections: [
          {
            id: 'u3l3s1', sectionNumber: 1, titleEn: 'Parallel Lines Definition', titleAr: 'تعريف المستقيمات المتوازية', path: '/unit/3/lesson/3/section/1', mode: 'Comparison',
            description: "Comparison: Two horizontal line tracks L1 and L2 extend indefinitely across grid with non-intersection badge L1 cap L2 = empty set. Transversal track T intersects both tracks at vertices A and B, creating 8 numbered angle arcs. User drag handle tilts transversal T while non-intersection badge persists. Final visual state displays parallel tracks cut by transversal with 8 illuminated angle arcs, visually correcting the misconception that non-intersecting lines in different planes are automatically parallel."
          },
          {
            id: 'u3l3s2', sectionNumber: 2, titleEn: 'Alternate Angles (Z-Shape)', titleAr: 'الزوايا المتبادلة', path: '/unit/3/lesson/3/section/2', mode: 'Process',
            description: "Process: Parallel lines L1 and L2 cut by transversal T display glowing yellow Z-shape wireframe overlay. Interior crook angles highlight at 65 degrees each with equality badge 'Alternate Angles Equal (Z-Shape)'. Transversal tilt handle adjusts angle to 40 degrees, causing both Z-crook angles to recalculate synchronously to 40 degrees. Final visual state locks equal alternate interior angles inside Z-shape wireframe, visually correcting the misconception that alternate angles add up to 180 degrees."
          },
          {
            id: 'u3l3s3', sectionNumber: 3, titleEn: 'Corresponding Angles (F-Shape)', titleAr: 'الزوايا المتناظرة', path: '/unit/3/lesson/3/section/3', mode: 'Process',
            description: "Process: Parallel lines L1 and L2 cut by transversal T display cyan F-shape wireframe overlay. Corresponding under-arm angles highlight in green at 110 degrees each with equality badge 'Corresponding Angles Equal (F-Shape)'. F-shape rotates 180 degrees into inverted orientation, highlighting new corresponding angle pair at 70 degrees each. Final visual state displays rotatable F-shape overlay with equal corresponding angle readers, visually correcting the misconception that corresponding angles occupy opposite sides of the transversal."
          },
          {
            id: 'u3l3s4', sectionNumber: 4, titleEn: 'Interior Angles (U-Shape)', titleAr: 'الزوايا الداخلية', path: '/unit/3/lesson/3/section/4', mode: 'Process',
            description: "Process: Parallel lines L1 and L2 cut by transversal T display orange U/C-shape interior wireframe overlay. Upper interior angle displays 115 degrees and lower interior angle displays 65 degrees. Summation pod connects both angles evaluating 115 + 65 = 180 degrees with badge 'Interior Angles Supplementary (U-Shape)'. User slider adjusts upper interior angle to 130 degrees while lower angle updates to 50 degrees to preserve 180-degree sum. Final visual state locks supplementary U-shape interior angle pair, visually correcting the misconception that interior angles on the same side of the transversal are equal."
          },
          {
            id: 'u3l3s5', sectionNumber: 5, titleEn: 'Equal Intercept Theorem', titleAr: 'نظرية القواطع المتساوية', path: '/unit/3/lesson/3/section/5', mode: 'Parallel Flow',
            description: "Parallel Flow: Three parallel horizontal lines L1, L2, L3 cut across two transversals T1 and T2. Transversal 1 displays equal segment markers AB = BC = 5 cm. Transversal 2 displays corresponding intercept segments DE and EF with active measuring calipers evaluating DE = EF = 8.2 cm under 'Equal Intercept Theorem' badge. Algebraic equation solver panel sets 3X - 2 = 13 to resolve X = 5. User slider adjusts transversal T2 angle while calipers confirm equal segment lengths DE and EF. Final visual state locks equal intercept caliper readouts on transversal 2, visually correcting the misconception that equal intercepts on one transversal force equal segment lengths across different transversals."
          }
        ]
      },
      {
        id: 'u3l4', lessonNumber: 4, titleEn: 'Triangles',
        sections: [
          {
            id: 'u3l4s1', sectionNumber: 1, titleEn: 'Interior Angle Sum', titleAr: 'مجموع الزوايا الداخلية', path: '/unit/3/lesson/4/section/1', mode: 'Process',
            description: "Process: Triangle ABC displays interior angles A (blue), B (green), and C (orange). Parallel top line DE draws through vertex A parallel to base BC. Alternate interior angles (Z-shape) map angle B onto DAB and angle C onto EAC along top line DE. Arc gauge sums DAB + A + EAC = 180 degrees, establishing interior angle sum A + B + C = 180 degrees. User drag handle adjusts triangle vertices while top parallel line mappings and 180-degree straight line sum update dynamically. Final visual state locks 180-degree straight line arc mapping across three reordered interior angles, visually correcting the misconception that triangle interior angle sums vary by triangle shape."
          },
          {
            id: 'u3l4s2', sectionNumber: 2, titleEn: 'Exterior Angle Theorem', titleAr: 'نظرية الزاوية الخارجة', path: '/unit/3/lesson/4/section/2', mode: 'Process',
            description: "Process: Triangle ABC extends base side BC into ray BD to form exterior angle ACD. Interior angle ACB measures 70 degrees, while remote interior angles A and B measure 60 degrees and 50 degrees. Supplementary test confirms exterior angle ACD = 180 - 70 = 110 degrees. Detachable angle wedges A (60 degrees) and B (50 degrees) slide across triangle interior and pack into exterior wedge ACD to display 60 + 50 = 110 degrees. User toggle alters remote interior angles while exterior wedge recalculates sum. Final visual state displays merged remote interior wedges packing exterior angle ACD, visually correcting the misconception that the exterior angle equals 180 degrees minus one remote interior angle."
          },
          {
            id: 'u3l4s3', sectionNumber: 3, titleEn: 'Triangle Classification', titleAr: 'تصنيف المثلثات', path: '/unit/3/lesson/4/section/3', mode: 'Comparison',
            description: "Comparison: Interactive triangle morphing workbench shifts vertices between classification states. State 1 displays acute angles 50, 60, 70 degrees with Acute badge. State 2 shifts vertex to 90 degrees with green square corner badge and Right badge. State 3 shifts vertex to 110 degrees with Obtuse badge. Side calipers measure side lengths: 3 equal 6 cm sides trigger Equilateral badge with all interior angles locking to 60 degrees; 2 equal sides trigger Isosceles badge with equal base angle overlays. User drag handles adjust vertices across angle and side length domains. Final visual state highlights classified triangle geometry with real-time protractor and caliper readouts, visually correcting the misconception that a triangle can contain more than one right or obtuse angle."
          },
          {
            id: 'u3l4s4', sectionNumber: 4, titleEn: 'Third Angle Equality', titleAr: 'تساوي الزاوية الثالثة', path: '/unit/3/lesson/4/section/4', mode: 'Parallel Flow',
            description: "Parallel Flow: Twin triangles ABC and DEF display side-by-side. Matching angle badges highlight m(A) = m(D) = 50 degrees and m(B) = m(E) = 60 degrees. Calculation nodes evaluate m(C) = 180 - 110 = 70 degrees and m(F) = 180 - 110 = 70 degrees, illuminating equal third angle badge m(C) = m(F). Secondary equilateral triangle displays interior angles 60, 60, 60 degrees and extends base to display exterior angle wedge 60 + 60 = 120 degrees. Final visual state locks third-angle equality link alongside equilateral 120-degree exterior angle readout, visually correcting the misconception that exterior angles of equilateral triangles equal 60 degrees."
          }
        ]
      },
      {
        id: 'u3l5', lessonNumber: 5, titleEn: 'Quadrilaterals',
        sections: [
          {
            id: 'u3l5s1', sectionNumber: 1, titleEn: 'Interior Sum of Quadrilaterals', titleAr: 'مجموع زوايا الشكل الرباعي', path: '/unit/3/lesson/5/section/1', mode: 'Process',
            description: "Process: General quadrilateral ABCD displays with 4 vertices, 4 side segments, and 2 diagonals. Diagonal AC illuminates in gold, splitting quadrilateral ABCD into two adjacent triangles ABC and ADC. Interior angle summation nodes evaluate triangle ABC (180 degrees) + triangle ADC (180 degrees) = 360 degrees. Circular angle gauge wraps around vertices A, B, C, and D, locking total interior angle sum m(A) + m(B) + m(C) + m(D) = 360 degrees. User drag handle deforms vertices while twin triangle angle sums maintain 360-degree lock. Final visual state displays 360-degree circular gauge overlay across diagonal-split triangles, visually correcting the misconception that quadrilateral angle sums vary by boundary shape."
          },
          {
            id: 'u3l5s2', sectionNumber: 2, titleEn: 'Properties of Parallelogram', titleAr: 'خواص متوازي الأضلاع', path: '/unit/3/lesson/5/section/2', mode: 'Process',
            description: "Process: Parallelogram ABCD displays with parallel arrow markers on opposite side pairs AB || CD and AD || BC. Caliper overlays measure opposite side equality AB = CD = 12 cm and AD = BC = 7 cm. Angle arc gauges match opposite angle pairs m(A) = m(C) = 110 degrees, while consecutive angle addition displays 110 + 70 = 180 degrees. Diagonals AC and BD intersect at central midpoint M, illuminating matching segment tick marks AM = MC and BM = MD. User drag handles tilt side angles while opposite equality and bisecting midpoint M recalculate dynamically. Final visual state highlights balanced parallelogram properties with verified side, angle, and diagonal midpoint equality locks, visually correcting the misconception that consecutive angles in a parallelogram are equal."
          },
          {
            id: 'u3l5s3', sectionNumber: 3, titleEn: 'Rectangle Formation', titleAr: 'تكوين المستطيل', path: '/unit/3/lesson/5/section/3', mode: 'Comparison',
            description: "Comparison: Parallelogram ABCD tilts interior angle A to 90 degrees, causing all 4 interior corners to snap to green 90-degree square icons to form a Rectangle. Diagonal measurement beams AC and BD illuminate, displaying identical lengths AC = 10 cm and BD = 10 cm (AC = BD). Central midpoint M highlights 4 equal bisected half-segments AM = MC = BM = MD = 5 cm. User slider tilts interior angle back and forth to contrast parallelogram with non-equal diagonals against rectangle with equal diagonals. Final visual state locks right-angled rectangle frame with equal diagonal measuring beams, visually correcting the misconception that rectangle diagonals intersect at 90-degree right angles."
          },
          {
            id: 'u3l5s4', sectionNumber: 4, titleEn: 'Rhombus Formation', titleAr: 'تكوين المعين', path: '/unit/3/lesson/5/section/4', mode: 'Process',
            description: "Process: Parallelogram ABCD adjusts side lengths until top side AB equals adjacent side BC, morphing into a Rhombus with 4 matching gold side ticks AB = BC = CD = DA = 8 cm. Diagonals AC and BD intersect at point M, snapping into a 90-degree perpendicular intersection with a green square corner badge (AC perpendicular to BD). Diagonal ray AC highlights as an angle bisector, splitting vertex angle A into two equal 35-degree half-angles marked with star badges. User slider alters side lengths while 4-side equality and 90-degree diagonal intersection badge persist. Final visual state displays equilateral rhombus boundary with 90-degree diagonal intersection lock, visually correcting the misconception that rhombus diagonals are equal in length."
          },
          {
            id: 'u3l5s5', sectionNumber: 5, titleEn: 'Square & Trapezium', titleAr: 'المربع وشبه المنحرف', path: '/unit/3/lesson/5/section/5', mode: 'Parallel Flow',
            description: "Parallel Flow: Rhombus ABCD flexes an interior angle to 90 degrees, morphing into a Square with combined gold property overlays: 4 equal 8 cm sides, 4 right-angle 90-degree corners, equal diagonals (AC = BD = 11.3 cm), and 90-degree perpendicular diagonals (AC perpendicular to BD bisecting vertex angles at 45 degrees). Secondary display panel isolates Trapezium ABCD with top/bottom parallel arrows AB || CD and non-parallel leg markers AD non-parallel to BC. Isosceles toggle sets leg equality AD = BC, illuminating equal base angle arcs and equal diagonals. Final visual state contrasts super-symmetric square frame against single-parallel-pair trapezium boundary, visually correcting the misconception that a trapezium is a parallelogram."
          }
        ]
      },
      {
        id: 'u3l6', lessonNumber: 6, titleEn: 'Special Quadrilaterals',
        sections: [
          {
            id: 'u3l6s1', sectionNumber: 1, titleEn: 'Parallelogram to Rectangle', titleAr: 'من متوازي الأضلاع إلى مستطيل', path: '/unit/3/lesson/6/section/1', mode: 'Process',
            description: "Process: Tilted parallelogram ABCD displays with unequal diagonals AC = 12 cm and BD = 8 cm. Flex handle adjusts interior angle A to 90 degrees, causing all 4 interior corners to snap to green 90-degree square icons to form a Rectangle. Parallel measurement calipers on diagonals AC and BD adjust until readouts match at AC = BD = 10 cm. User interaction slider flexes interior angle and toggles diagonal equality mode. Final visual state locks right-angled rectangle frame with equal diagonal calipers, visually correcting the misconception that equal diagonals alone turn any quadrilateral into a rectangle without initial parallelism."
          },
          {
            id: 'u3l6s2', sectionNumber: 2, titleEn: 'Parallelogram to Rhombus', titleAr: 'من متوازي الأضلاع إلى معين', path: '/unit/3/lesson/6/section/2', mode: 'Process',
            description: "Process: Parallelogram ABCD sits with unequal adjacent sides AB = 10 cm and BC = 6 cm. Side length adjustment tool expands BC to 10 cm, illuminating gold single-tick marks across all 4 sides AB = BC = CD = DA = 10 cm to form a Rhombus. Diagonal intersection beam rotates inside frame until central intersection angle at point M evaluates to 90 degrees with a green square corner badge. User interaction button toggles between side-length equalization and diagonal 90-degree alignment. Final visual state displays equilateral rhombus boundary with 90-degree diagonal intersection badge, visually correcting the misconception that a rhombus must have right interior angles."
          },
          {
            id: 'u3l6s3', sectionNumber: 3, titleEn: 'Dual Upgrade to Square', titleAr: 'الترقية المزدوجة إلى مربع', path: '/unit/3/lesson/6/section/3', mode: 'Process',
            description: "Process: Parallelogram ABCD enters dual-upgrade chamber. Rectangle upgrade card 'One 90-Degree Angle' inserts on left; Rhombus upgrade card 'Equal Adjacent Sides' inserts on right. Shape boundary morphs into a Square, illuminating 4 equal 8 cm side ticks, 4 right-angle 90-degree corners, equal diagonal calipers (AC = BD = 11.3 cm), and a 90-degree perpendicular diagonal intersection badge (AC perpendicular to BD). User interaction allows toggling individual upgrade cards to observe partial vs complete square transformations. Final visual state locks fully upgraded square boundary with combined rectangle and rhombus property overlays, visually correcting the misconception that a single property upgrade is sufficient to form a square from a general parallelogram."
          },
          {
            id: 'u3l6s4', sectionNumber: 4, titleEn: 'Quadrilateral Family Tree', titleAr: 'شجرة عائلة الأشكال الرباعية', path: '/unit/3/lesson/6/section/4', mode: 'Time Evolution',
            description: "Time Evolution: Quadrilateral family tree diagram originates at top node 'Quadrilateral'. Primary branch splits into 'Parallelogram' (flashing 2 parallel arrow pairs) and 'Trapezium' (flashing 1 parallel arrow pair). 'Parallelogram' node branches down into 'Rectangle' (illuminating 90-degree corner badge) and 'Rhombus' (illuminating 4 equal side ticks). Arrows from Rectangle and Rhombus converge downward into 'Square' node, displaying all combined property icons. User selector clicks individual nodes to highlight property inheritance paths. Final visual state displays complete illuminated family tree tree mapping property inheritance down to the square node, visually correcting the misconception that a square is an independent shape unrelated to rectangles and rhombuses."
          }
        ]
      },
      {
        id: 'u3l7', lessonNumber: 7, titleEn: 'Polygons',
        sections: [
          {
            id: 'u3l7s1', sectionNumber: 1, titleEn: 'Convex vs Concave Polygons', titleAr: 'المضلعات المحدبة والمقعرة', path: '/unit/3/lesson/7/section/1', mode: 'Process',
            description: "Process: Convex 6-sided polygon displays with green interior angle arcs all under 180 degrees. Vertex handle E is dragged inward toward polygon center, causing interior angle E to expand past a straight line to 220 degrees with a red reflex arc badge 'Concave Polygon (Reflex Angle > 180)'. Boundary segment tool draws line between interior points P1 and P2 that passes outside the boundary. User drag handle alters vertex positions to test convex vs concave transformations. Final visual state contrasts green convex boundary against red reflex-angle concave boundary, visually correcting the misconception that any polygon with straight sides is automatically convex."
          },
          {
            id: 'u3l7s2', sectionNumber: 2, titleEn: 'Polygon Interior Angles Sum', titleAr: 'مجموع الزوايا الداخلية للمضلع', path: '/unit/3/lesson/7/section/2', mode: 'Process',
            description: "Process: 5-sided pentagon ABCDE displays (n = 5). Diagonal rays originate from single vertex A to non-adjacent vertices C and D, partitioning pentagon into 3 internal triangles (n - 2 = 3). Each triangle illuminates with a 180-degree badge, adding to total interior sum 180 + 180 + 180 = 540 degrees under formula evaluator S = (5 - 2) x 180 = 540 degrees. User slider increases side count to n = 6 (hexagon), forming 4 internal triangles evaluating to 720 degrees. Final visual state locks single-vertex triangle partition with verified formula sum, visually correcting the misconception that multiplying 180 by the side count n gives the interior angle sum."
          },
          {
            id: 'u3l7s3', sectionNumber: 3, titleEn: 'Regular Polygons', titleAr: 'المضلعات المنتظمة', path: '/unit/3/lesson/7/section/3', mode: 'Comparison',
            description: "Comparison: Irregular 6-sided polygon transforms under 'Regularize' filter into a Regular Hexagon with matching 5 cm side-length calipers and equal 120-degree interior corner protractor badges. Calculation node evaluates individual interior angle theta = (6 - 2) x 180 / 6 = 720 / 6 = 120 degrees. User slider updates side count to Regular Octagon (n = 8), updating interior protractor badges to 135 degrees. Final visual state displays fully symmetrical regular polygon boundary with equal side calipers and evaluated interior angle protractors, visually correcting the misconception that equilateral polygons are automatically equiangular."
          },
          {
            id: 'u3l7s4', sectionNumber: 4, titleEn: 'Exterior Angles & Diagonals', titleAr: 'الزوايا الخارجة والأقطار', path: '/unit/3/lesson/7/section/4', mode: 'Parallel Flow',
            description: "Parallel Flow: Convex pentagon extends all 5 sides into rays forming 5 exterior angle wedges. The 5 exterior wedges detach, slide together, and wrap around a central point to complete a 360-degree full circle gauge with badge 'Exterior Angle Sum = 360 Degrees (Constant)'. Secondary display shows hexagon (n = 6) with diagonal lines tracing between non-adjacent vertices, while counter node evaluates D = 6(6 - 3) / 2 = 9 diagonals. User slider alters polygon side count while exterior circle gauge maintains 360-degree lock and diagonal counter recalculates. Final visual state contrasts constant 360-degree exterior circle gauge against dynamic diagonal wireframe network, visually correcting the misconception that exterior angle sums increase as side count increases."
          }
        ]
      },
      {
        id: 'u3l8', lessonNumber: 8, titleEn: 'Congruent Triangles',
        sections: [
          {
            id: 'u3l8s1', sectionNumber: 1, titleEn: 'Concept of Congruence', titleAr: 'مفهوم التطابق', path: '/unit/3/lesson/8/section/1', mode: 'Process',
            description: "Process: Line segments AB (6 cm) and CD (6 cm) slide atop each other, glowing green with badge AB equiv CD. Angles ABC (50 degrees) and DEF (50 degrees) sweep together and overlay seamlessly. Polygon ABCDE glides over polygon XYZWL, flashing matching corresponding sides (AB = XY, BC = YZ) and matching corresponding angles (m(A) = m(X)). Outcome badge displays ABCDE equiv XYZWL (Complete Coincidence). User drag handle aligns shapes atop each other. Final visual state locks superimposed congruent polygons with glowing boundary alignment, visually correcting the misconception that congruent shapes can have different side lengths or angle measures."
          },
          {
            id: 'u3l8s2', sectionNumber: 2, titleEn: 'SAS Congruence', titleAr: 'التطابق بضلعين وزاوية محصورة', path: '/unit/3/lesson/8/section/2', mode: 'Process',
            description: "Process: Triangles ABC and DEF display side-by-side. Matching side indicators highlight AB = DE = 5 cm and BC = EF = 8 cm. Included angle arcs highlight between sides at m(B) = m(E) = 40 degrees. Triangle ABC lifts, rotates, and glides over triangle DEF, locking into position under badge 'SAS Congruence (ABC equiv DEF)'. User interaction allows dragging non-included angles to observe congruence failure. Final visual state displays superimposed congruent triangles with illuminated SAS included angle lock, visually correcting the misconception that any non-included equal angle satisfies triangle congruence."
          },
          {
            id: 'u3l8s3', sectionNumber: 3, titleEn: 'ASA and SSS Congruence', titleAr: 'التطابق بزاويتين وضلع / التطابق بثلاث أضلاع', path: '/unit/3/lesson/8/section/3', mode: 'Parallel Flow',
            description: "Parallel Flow: ASA animation displays base side BC = EF = 7 cm with glowing adjacent base angles m(B) = m(E) = 50 degrees and m(C) = m(F) = 60 degrees. Extending angle rays intersect at top vertices A and D to generate congruent triangles ABC equiv DEF. SSS animation displays side calipers matching all three side pairs AB = DE = 6 cm, BC = EF = 8 cm, and AC = DF = 10 cm, locking triangle ABC atop DEF with badge 'SSS Congruence'. User toggle switches between ASA ray extension and SSS caliper lock modes. Final visual state contrasts fixed angle ray intersections against rigid 3-caliper side locks, visually correcting the misconception that three equal angles (AAA) prove triangle congruence."
          },
          {
            id: 'u3l8s4', sectionNumber: 4, titleEn: 'RHS Congruence', titleAr: 'التطابق بوتر وضلع في المثلث القائم', path: '/unit/3/lesson/8/section/4', mode: 'Process',
            description: "Process: Right triangles ABC and DEF display green 90-degree square corner icons at vertices B and E. Hypotenuse measurement beams illuminate opposite right angles at AC = DF = 10 cm, and vertical leg calipers highlight at AB = DE = 6 cm. Triangle ABC glides over DEF, snapping into place under badge 'RHS Right-Triangle Congruence (ABC equiv DEF)'. User interaction button toggles hypotenuse vs leg inputs. Final visual state locks right-angle sensor lock with hypotenuse measuring beams and leg caliper overlays, visually correcting the misconception that RHS applies to non-right-angled triangles."
          }
        ]
      },
      {
        id: 'u3l9', lessonNumber: 9, titleEn: 'Geometric Constructions',
        sections: [
          {
            id: 'u3l9s1', sectionNumber: 1, titleEn: 'Perpendicular Bisector', titleAr: 'المنصف العمودي', path: '/unit/3/lesson/9/section/1', mode: 'Process',
            description: "Process: Line segment AB (8 cm) rests on grid. Compass needle anchors at endpoint A with opening radius r > 4 cm, drawing top and bottom arc sweeps. Compass moves to endpoint B with fixed radius r, drawing intersecting arc sweeps at points P and Q. Straightedge connects P and Q to draw line PQ. A 90-degree green square corner illuminates at intersection M on segment AB with matching half-length tick marks AM = MB = 4 cm. User radius slider adjusts compass opening to verify arc intersection requirements. Final visual state locks perpendicular bisector PQ with 90-degree angle and equal midpoint segment indicators, visually correcting the misconception that any line passing through a midpoint is an axis of symmetry."
          },
          {
            id: 'u3l9s2', sectionNumber: 2, titleEn: 'Angle Bisector Construction', titleAr: 'رسم منصف الزاوية', path: '/unit/3/lesson/9/section/2', mode: 'Process',
            description: "Process: Angle ABC (70 degrees) displays on plane. Compass needle anchors at vertex B, sweeping an arc that cuts ray BA at X and ray BC at Y. Compass needle anchors at X to draw an interior arc, then anchors at Y to draw an intersecting interior arc at point D. Straightedge draws ray BD through D. Two 35-degree sector protractors illuminate with matching star badges. User interaction allows adjusting initial angle measure while bisector construction steps re-execute dynamically. Final visual state highlights twin 35-degree angle sectors divided by ray BD, visually correcting the misconception that angle bisection requires protractor angle measurements."
          },
          {
            id: 'u3l9s3', sectionNumber: 3, titleEn: 'Perpendicular from Point', titleAr: 'رسم عمود من نقطة', path: '/unit/3/lesson/9/section/3', mode: 'Process',
            description: "Process: Straight line L displays with external point P floating above. Compass needle at P sweeps a downward arc cutting line L at X and Y. Compass needle moves to X to sweep an arc below L, then to Y to draw an intersecting arc at point Q. Straightedge connects P and Q to form line PQ. A 90-degree green square corner badge illuminates at intersection point M on line L with shortest perpendicular distance badge PM. User toggle switches between point outside vs point on line modes. Final visual state locks perpendicular line PQ intersecting line L at 90 degrees, visually correcting the misconception that sloped lines represent shortest distance from a point to a line."
          },
          {
            id: 'u3l9s4', sectionNumber: 4, titleEn: 'Copying an Angle', titleAr: 'نسخ زاوية', path: '/unit/3/lesson/9/section/4', mode: 'Process',
            description: "Process: Source angle ABC (55 degrees) and target ray DE display side-by-side. Compass sweeps arc radius R at vertex B (cutting X and Y) and at vertex D (cutting DE at M). Compass measures chord distance XY on source angle. Compass needle moves to M on target ray, sweeping chord radius XY to cross arc at N. Straightedge draws ray DN, displaying matching 55-degree protractor readouts and badge NDE equiv ABC. User drag handle adjusts source angle measure while target angle updates synchronously through compass arc transfer. Final visual state displays copied 55-degree angle NDE with matching arc radii overlays, visually correcting the misconception that copying an angle requires protractor degree measurements."
          },
          {
            id: 'u3l9s5', sectionNumber: 5, titleEn: 'Constructing Parallel Lines', titleAr: 'رسم المستقيمات المتوازية', path: '/unit/3/lesson/9/section/5', mode: 'Parallel Flow',
            description: "Parallel Flow: Line L displays with point P floating above. Transversal line T draws through P and intersects line L at point A, forming interior angle 1. Angle copying construction duplicates angle 1 at vertex P as alternate angle 2. Line M draws through P along new angle ray. A glowing yellow Z-shape wireframe highlights angle 1 = angle 2, illuminating parallel arrow markers on lines L and M (M || L). User slider adjusts transversal angle while alternate angle equality and parallel arrows maintain line alignment. Final visual state locks parallel lines L and M with highlighted Z-shape alternate angle overlay, visually correcting the misconception that parallel lines can be constructed accurately by freehand drawing without angle duplication."
          }
        ]
      }
    ]
  },
  {
    id: 'u4', unitNumber: 4, titleEn: 'Statistics', color: 'bg-amber-500',
    lessons: [
      {
        id: 'u4l1', lessonNumber: 1, titleEn: 'Measures of Central Tendency',
        sections: [
          {
            id: 'u4l1s1', sectionNumber: 1, titleEn: 'Arithmetic Mean', titleAr: 'الوسط الحسابي', path: '/unit/4/lesson/1/section/1', mode: 'Process',
            description: "Process: 5 vertical bar towers display values 3, 5, 7, 9, 6. Horizontal balance line sweeps down over towers, redistributing height blocks from tall towers to short towers until all 5 towers equalize at height 6. Formula badge evaluates Mean = (3 + 5 + 7 + 9 + 6) / 5 = 30 / 5 = 6. User slider alters individual bar heights while fluid equalizer line recalculates mean height dynamically. Final visual state locks equalized bar height at 6 units, visually correcting the misconception that the mean is simply the middle number of an unsorted list."
          },
          {
            id: 'u4l1s2', sectionNumber: 2, titleEn: 'Median Selection', titleAr: 'تحديد الوسيط', path: '/unit/4/lesson/1/section/2', mode: 'Process',
            description: "Process: Unsorted data blocks 12, 4, 19, 7, 15 load onto sorting conveyor belt. Conveyor belt arranges blocks in ascending order 4, 7, 12, 15, 19. Outer block pairs (4 and 19, 7 and 15) gray out, highlighting central median block 12 at rank 3. Secondary even dataset 4, 7, 10, 14, 18, 22 highlights two middle blocks 10 and 14, evaluating median average (10 + 14) / 2 = 12. User toggle switches between odd and even dataset lengths. Final visual state highlights sorted central median block with rank calculation badge, visually correcting the misconception of selecting a median from unsorted data."
          },
          {
            id: 'u4l1s3', sectionNumber: 3, titleEn: 'Mode Evaluation', titleAr: 'حساب المنوال', path: '/unit/4/lesson/1/section/3', mode: 'Process',
            description: "Process: Data elements 5, 7, 5, 9, 5, 3 drop into vertical frequency bins. Bin 3 holds 1 block, Bin 7 holds 1 block, Bin 9 holds 1 block, and Bin 5 holds 3 blocks. Bin 5 glows brightly, elevating above surrounding bins with frequency gauge reading 3 and crown badge 'Mode = 5 (Most Frequent)'. User interaction allows adding new data points to observe frequency bin heights update in real-time. Final visual state locks elevated frequency bin 5 with crown badge overlay, visually correcting the misconception that the mode is the highest numerical value rather than the most frequent value."
          },
          {
            id: 'u4l1s4', sectionNumber: 4, titleEn: 'Outlier Impact', titleAr: 'تأثير القيم المتطرفة', path: '/unit/4/lesson/1/section/4', mode: 'Comparison',
            description: "Comparison: Data point cluster 2, 3, 4, 5, 6 displays with Mean = 4 and Median = 4. Extreme outlier block 100 enters dataset. Mean indicator pointer shoots right to 20, while Median pointer shifts slightly to 4.5, remaining anchored inside dense data cluster. Diagnostic panel displays 'Use Median for Outlier-Skewed Data; Use Mean for Uniform Data'. User slider adjusts outlier value from 100 to 500 to observe mean divergence against median stability. Final visual state contrasts outlier-skewed mean pointer against stable median cluster anchor, visually correcting the misconception that the mean is always the most accurate central representative."
          }
        ]
      }
    ]
  }
];
