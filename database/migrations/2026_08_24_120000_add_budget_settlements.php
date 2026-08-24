<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uzavřené vyrovnání.
 *
 * Dosud se dluh počítal ze všech dělených položek za celou dobu rozpočtu. Když si ho
 * dvojice poslala, číslo svítilo dál a příští měsíc se k němu přičetlo nové — takže
 * panel po půl roce ukazoval součet všeho, co kdy kdo za koho zaplatil, místo toho,
 * co ještě zbývá vyrovnat.
 *
 * Řeší se to datem, ne příznakem u položek. Označit dvě stě řádků za vyrovnané by
 * znamenalo dvě stě zápisů a nešlo by to vzít zpět; jedno datum říká totéž, dá se
 * smazat a zůstane po něm historie, kdo kdy s kým co srovnal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();

            // Po měnách zvlášť, stejně jako se počítá samotný dluh. Kurz nemáme.
            $table->string('currency', 3);

            // Do kterého dne včetně je vyrovnáno. Položky s pozdějším datem se počítají
            // dál — kdo srovná účty v neděli, v pondělí zase začíná od nuly.
            $table->date('settled_through');

            $table->decimal('amount', 12, 2);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['budget_id', 'currency', 'settled_through']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_settlements');
    }
};
