<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One inquiry, one job order number — so a number can be on several orders.
 *
 * A brief carries several designs, and each approved design is written up as
 * its own order because each one is its own run of work: its own sizes, its
 * own press, its own steps on the floor. They are still ONE job to the client
 * and to the office, and they were getting a number each — which is how one
 * brief's 830 pieces ended up under IC2026-00005 and IC2026-01174 with nothing
 * on either sheet saying the two belonged together.
 *
 * So the number stops being unique. It is the JOB's number, and however many
 * orders the brief produces, they all sit under it.
 *
 * What makes them one job is the BRIEF they came from, not the client: the
 * same person can have two unrelated enquiries running at once, and those are
 * two jobs with two numbers. Two briefs under one number is not a job with six
 * parts, it is two jobs nobody can tell apart, and the controller refuses it.
 *
 * The index stays, without the uniqueness: every list on the system searches
 * orders by number, and those searches now return the set rather than the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropUnique('production_orders_order_number_unique');
            $table->index('order_number', 'production_orders_order_number_index');
        });
    }

    public function down(): void
    {
        // Only reversible while no number is actually shared — which is the
        // honest position: putting the uniqueness back cannot invent numbers
        // for the orders that have since been written under a shared one.
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropIndex('production_orders_order_number_index');
            $table->unique('order_number', 'production_orders_order_number_unique');
        });
    }
};
